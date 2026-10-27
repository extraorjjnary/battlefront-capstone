<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Models\PcBuildEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavedPcBuildService
{
    /** @return Collection<int, PcBuild> */
    public function list(User $customer): Collection
    {
        $this->ensureCustomer($customer);

        return PcBuild::query()->where('user_id', $customer->id)->with(['items.product'])->latest('updated_at')->get();
    }

    /** @param array{name: string, mode: string, budget?: string|null, components: list<array<string, mixed>>} $data */
    public function create(User $customer, array $data): PcBuild
    {
        $this->ensureCustomer($customer);

        return DB::transaction(function () use ($customer, $data): PcBuild {
            $build = PcBuild::query()->create([
                'user_id' => $customer->id,
                'name' => $data['name'],
                'mode' => $data['mode'],
                'budget' => $data['budget'] ?? null,
            ]);
            $this->replaceItems($build, $data['components']);
            $this->record($build, $customer, 'saved');

            return $build->load(['items.product']);
        }, attempts: 3);
    }

    /** @param array{name: string, mode: string, budget?: string|null, edit_version: int, components: list<array<string, mixed>>} $data */
    public function update(User $customer, string $uuid, array $data): PcBuild
    {
        $this->ensureCustomer($customer);

        return DB::transaction(function () use ($customer, $uuid, $data): PcBuild {
            $build = $this->ownedBuild($customer, $uuid, true);
            if ($build->edit_version !== $data['edit_version']) {
                throw ValidationException::withMessages(['edit_version' => 'This build changed in another session. Reload it before saving.']);
            }

            $build->update([
                'name' => $data['name'],
                'mode' => $data['mode'],
                'budget' => $data['budget'] ?? null,
                'edit_version' => $build->edit_version + 1,
                'share_token' => null,
            ]);
            $build->items()->delete();
            $this->replaceItems($build, $data['components']);
            $this->record($build, $customer, 'updated');

            return $build->load(['items.product']);
        }, attempts: 3);
    }

    public function duplicate(User $customer, string $uuid): PcBuild
    {
        $this->ensureCustomer($customer);

        return DB::transaction(function () use ($customer, $uuid): PcBuild {
            $source = $this->ownedBuild($customer, $uuid)->load('items');
            $duplicate = PcBuild::query()->create([
                'user_id' => $customer->id,
                'name' => $source->name.' (copy)',
                'mode' => $source->mode,
                'budget' => $source->budget,
            ]);

            foreach ($source->items as $item) {
                $duplicate->items()->create([
                    'product_id' => $item->product_id,
                    'component_specification_revision_id' => $item->component_specification_revision_id,
                    'component_type' => $item->component_type,
                    'slot_key' => $item->slot_key,
                    'quantity' => $item->quantity,
                    'position' => $item->position,
                    'configuration' => $item->configuration,
                ]);
            }

            $this->record($duplicate, $customer, 'duplicated');

            return $duplicate->load(['items.product']);
        }, attempts: 3);
    }

    public function delete(User $customer, string $uuid): void
    {
        $this->ensureCustomer($customer);

        DB::transaction(function () use ($customer, $uuid): void {
            $build = $this->ownedBuild($customer, $uuid, true);
            $this->record($build, $customer, 'deleted');
            $build->delete();
        }, attempts: 3);
    }

    public function share(User $customer, string $uuid): string
    {
        $this->ensureCustomer($customer);
        $build = $this->ownedBuild($customer, $uuid, true);
        $build->forceFill(['share_token' => (string) Str::uuid()])->save();
        $this->record($build, $customer, 'shared');

        return route('pc-builds.shared.show', ['token' => $build->share_token]);
    }

    public function stopSharing(User $customer, string $uuid): void
    {
        $this->ensureCustomer($customer);
        $build = $this->ownedBuild($customer, $uuid, true);
        $build->forceFill(['share_token' => null])->save();
    }

    public function findShared(string $token): ?PcBuild
    {
        return PcBuild::query()->where('share_token', $token)->with(['items.product'])->first();
    }

    /** @return array<string, mixed> */
    public function present(PcBuild $build): array
    {
        return [
            'uuid' => $build->uuid,
            'name' => $build->name,
            'mode' => $build->mode,
            'budget' => $build->budget,
            'edit_version' => $build->edit_version,
            'share_enabled' => $build->share_token !== null,
            'items' => $build->items->map(fn ($item): array => [
                'key' => $item->slot_key,
                'product_id' => $item->product_id,
                'component_type' => $item->component_type,
                'quantity' => $item->quantity,
                'configuration' => $item->configuration ?? [],
                'name' => $item->product?->name,
                'price' => $item->product?->discount_price ?? $item->product?->price,
            ])->all(),
        ];
    }

    /** @param list<array<string, mixed>> $components */
    private function replaceItems(PcBuild $build, array $components): void
    {
        if ($components === []) {
            return;
        }

        $products = Product::query()
            ->whereIn('id', array_column($components, 'product_id'))
            ->with('componentProfile.revisions')
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($components)) {
            throw ValidationException::withMessages(['components' => 'A selected catalog product no longer exists.']);
        }

        foreach ($components as $position => $component) {
            /** @var Product $product */
            $product = $products->get($component['product_id']);
            $profile = $product->componentProfile;
            if ($profile === null || $profile->component_type === '') {
                throw ValidationException::withMessages(['components' => 'A selected product is not configured for the PC Builder.']);
            }

            $revision = $profile->revisions->firstWhere('revision', $profile->current_revision);
            $slotKey = preg_replace('/[^A-Za-z0-9:_-]/', '-', $component['key']);
            if ($slotKey === null || $slotKey === '') {
                throw ValidationException::withMessages(['components' => 'Each build component needs a valid slot identifier.']);
            }

            $build->items()->create([
                'product_id' => $product->id,
                'component_specification_revision_id' => $revision?->id,
                'component_type' => $profile->component_type,
                'slot_key' => $slotKey,
                'quantity' => $component['quantity'],
                'position' => $position,
                'configuration' => $component['configuration'] ?? [],
            ]);
        }
    }

    private function ownedBuild(User $customer, string $uuid, bool $lock = false): PcBuild
    {
        $query = PcBuild::query()->where('user_id', $customer->id)->where('uuid', $uuid);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function ensureCustomer(User $customer): void
    {
        if (! $customer->can('use-customer-cart')) {
            throw new AuthorizationException('PC Builder saved builds are available to customer accounts.');
        }
    }

    private function record(PcBuild $build, User $customer, string $eventType): void
    {
        $items = $build->items()->orderBy('slot_key')->get(['slot_key', 'product_id', 'component_type', 'quantity']);
        PcBuildEvent::query()->create([
            'pc_build_id' => $build->id,
            'user_id' => $customer->id,
            'event_type' => $eventType,
            'configuration_fingerprint' => hash('sha256', $items->toJson()),
            'context' => [
                'ruleset_version' => CompatibilityEngine::RULESET_VERSION,
                'components' => $items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'component_type' => $item->component_type,
                    'quantity' => $item->quantity,
                ])->all(),
            ],
            'occurred_at' => now(),
        ]);
    }
}
