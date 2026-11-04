<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PcBuilderService
{
    public function __construct(private readonly CompatibilityEngine $compatibilityEngine) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function catalog(?User $customer = null): array
    {
        $wishlistedProductIds = $customer?->wishlistItems()->pluck('product_id')->flip();

        $products = Product::query()
            ->customerEligible()
            ->whereHas('componentProfile', fn ($query) => $query->whereIn('component_type', ComponentTypes::all()))
            ->with(['componentProfile.revisions', 'inventory'])
            ->orderBy('name')
            ->get();
        $catalog = array_fill_keys(ComponentTypes::all(), []);
        foreach ($products as $product) {
            $catalog[$product->componentProfile->component_type][] = $this->present($product, $wishlistedProductIds?->has($product->id) ?? false);
        }

        return $catalog;
    }

    /**
     * @param  list<array{key: string, product_id: int, quantity: int, configuration?: array<string, mixed>}>  $selection
     * @return array<string, mixed>
     */
    public function validateSelection(array $selection): array
    {
        if ($selection === []) {
            return $this->compatibilityEngine->evaluate([]);
        }

        $products = Product::query()
            ->customerAvailable()
            ->whereIn('id', array_column($selection, 'product_id'))
            ->with(['componentProfile.revisions', 'inventory'])
            ->get()
            ->keyBy('id');

        if ($products->count() !== count(array_unique(array_column($selection, 'product_id')))) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected products are no longer available. Refresh the component list and try again.',
            ]);
        }

        $resolved = [];
        $requestedQuantities = collect($selection)->groupBy('product_id')->map(fn ($items): int => $items->sum('quantity'));
        foreach ($selection as $item) {
            /** @var Product $product */
            $product = $products->get($item['product_id']);
            if ($requestedQuantities->get($product->id) > ($product->inventory->quantity ?? 0)) {
                throw ValidationException::withMessages(['components' => 'The selected quantities exceed current stock. Review the build and try again.']);
            }
            $profile = $product->componentProfile;
            $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);

            if ($profile === null || ! in_array($profile->component_type, ComponentTypes::all(), true)) {
                throw ValidationException::withMessages(['components' => 'A selected product is not configured as a PC Builder component.']);
            }

            $resolved[] = [
                'key' => $item['key'],
                'product_id' => $product->id,
                'component_type' => $profile->component_type,
                'quantity' => $item['quantity'],
                'configuration' => $item['configuration'] ?? [],
                'specifications_reviewed' => $profile->review_status === 'approved' && $revision?->review_status === 'approved'
                    && (! config('pc-builder.strict_selection_enabled') || app(ComponentReadinessService::class)->selectionIssues($profile) === []),
                'evidence' => $revision->evidence ?? [],
                'name' => $product->name,
                'specifications' => $profile->review_status === 'approved' && $revision?->review_status === 'approved'
                    ? $revision->specifications
                    : [],
            ];
        }

        return $this->compatibilityEngine->evaluate($resolved, (bool) config('pc-builder.strict_selection_enabled'));
    }

    /** @return array<string, mixed> */
    private function present(Product $product, bool $isWishlisted): array
    {
        $profile = $product->componentProfile;
        $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
        $isReviewed = $profile?->review_status === 'approved' && $revision?->review_status === 'approved';

        return [
            'id' => $product->id,
            'component_type' => $profile->component_type,
            'name' => $product->name,
            'brand' => $product->brand,
            'image_url' => $product->image_url,
            'price' => $product->discount_price ?? $product->price,
            'stock_quantity' => $product->inventory?->quantity,
            'specifications' => $isReviewed ? $revision->specifications : [],
            'specifications_reviewed' => $isReviewed,
            'selection_issues' => app(ComponentReadinessService::class)->selectionIssues($profile),
            'manufacturer_part_number' => $profile->manufacturer_part_number,
            'hardware_revision' => $profile->hardware_revision,
            'evidence' => $revision->evidence ?? [],
            'is_wishlisted' => $isWishlisted,
        ];
    }
}
