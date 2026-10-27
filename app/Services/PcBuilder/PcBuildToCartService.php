<?php

namespace App\Services\PcBuilder;

use App\Actions\Cart\CartOperationException;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\PcBuild;
use App\Models\PcBuildCartGroup;
use App\Models\PcBuildEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PcBuildToCartService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CompatibilityEngine $compatibilityEngine,
    ) {}

    public function add(User $customer, string $buildUuid): PcBuildCartGroup
    {
        return DB::transaction(function () use ($customer, $buildUuid): PcBuildCartGroup {
            $lockedCustomer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($lockedCustomer->role !== UserRole::Customer) {
                throw new AuthorizationException('PC build purchases are available to customers only.');
            }

            $build = PcBuild::query()
                ->where('user_id', $lockedCustomer->id)
                ->where('uuid', $buildUuid)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();
            if ($build->items->isEmpty()) {
                throw ValidationException::withMessages(['build' => 'Add components before putting this build in the cart.']);
            }

            $productIds = $build->items->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $categories = Category::query()->whereIn('id', $products->pluck('category_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $inventories = Inventory::query()->whereIn('product_id', $productIds)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $cart = Cart::query()->where('user_id', $lockedCustomer->id)->lockForUpdate()->first();
            if ($cart === null) {
                $cart = Cart::query()->create(['user_id' => $lockedCustomer->id]);
            }

            $existingQuantities = $cart->items()->whereIn('product_id', $productIds)->selectRaw('product_id, SUM(quantity) AS quantity_total')->groupBy('product_id')->pluck('quantity_total', 'product_id');
            $requestedQuantities = $build->items->groupBy('product_id')
                ->map(fn ($matchingItems): int => $matchingItems->sum('quantity'));
            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                $category = $product === null ? null : $categories->get($product->category_id);
                $inventory = $inventories->get($productId);
                if ($product === null || $category === null) {
                    throw ValidationException::withMessages(['build' => 'A component is no longer available in the catalog.']);
                }

                try {
                    $this->cartService->ensureAvailableForCheckout(
                        $product,
                        $category,
                        $inventory,
                        $requestedQuantities->get($productId) + (int) $existingQuantities->get($productId, 0),
                    );
                } catch (CartOperationException) {
                    throw ValidationException::withMessages(['build' => 'One or more components no longer have enough stock. Review the build and try again.']);
                }
            }

            $components = [];
            $snapshots = [];
            foreach ($build->items as $item) {
                /** @var Product|null $product */
                $product = $products->get($item->product_id);
                $category = $product === null ? null : $categories->get($product->category_id);
                if ($product === null || $category === null) {
                    throw ValidationException::withMessages(['build' => 'A component is no longer available in the catalog.']);
                }

                $profile = $product->componentProfile()->with('revisions')->first();
                $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
                $reviewed = $profile?->review_status === 'approved' && $revision?->review_status === 'approved';
                $specifications = $reviewed ? $revision->specifications : [];
                $components[] = [
                    'key' => $item->slot_key,
                    'product_id' => $product->id,
                    'component_type' => $profile->component_type ?? $item->component_type,
                    'quantity' => $item->quantity,
                    'configuration' => $item->configuration ?? [],
                    'specifications' => $specifications,
                ];
                $snapshots[] = [
                    'key' => $item->slot_key,
                    'product_id' => $product->id,
                    'component_type' => $profile->component_type ?? $item->component_type,
                    'quantity' => $item->quantity,
                    'configuration' => $item->configuration ?? [],
                    'specifications' => $specifications,
                    'specification_revision' => $reviewed ? $revision->revision : null,
                ];
            }

            $validation = $this->compatibilityEngine->evaluate($components);
            if (! $validation['complete']) {
                throw ValidationException::withMessages(['build' => 'This build is incomplete or needs compatibility verification before it can be added to the cart.']);
            }

            $group = PcBuildCartGroup::query()->create([
                'cart_id' => $cart->id,
                'name' => $build->name,
                'mode' => $build->mode,
                'ruleset_version' => $validation['ruleset_version'],
                'configuration_snapshot' => [
                    'components' => $snapshots,
                    'validation' => $validation,
                ],
            ]);

            foreach ($build->items->groupBy('product_id') as $productId => $matchingItems) {
                $cartItem = $cart->items()->make([
                    'product_id' => $productId,
                    'quantity' => $matchingItems->sum('quantity'),
                    'pc_build_cart_group_id' => $group->id,
                ]);
                $cartItem->pc_build_group_identity = $group->id;
                $cartItem->save();
            }

            PcBuildEvent::query()->create([
                'pc_build_id' => $build->id,
                'user_id' => $lockedCustomer->id,
                'event_type' => 'added_to_cart',
                'configuration_fingerprint' => hash('sha256', $build->items()->orderBy('slot_key')->get([
                    'slot_key', 'product_id', 'component_type', 'quantity',
                ])->toJson()),
                'context' => [
                    'ruleset_version' => $validation['ruleset_version'],
                    'cart_group_uuid' => $group->uuid,
                    'components' => array_map(fn (array $item): array => [
                        'product_id' => $item['product_id'],
                        'component_type' => $item['component_type'],
                        'quantity' => $item['quantity'],
                    ], $snapshots),
                    'validation_status' => $validation['status'],
                    'warning_count' => count(array_filter($validation['findings'], fn (array $finding): bool => $finding['status'] === 'warning')),
                ],
                'occurred_at' => now(),
            ]);

            return $group->load('items');
        }, attempts: 3);
    }
}
