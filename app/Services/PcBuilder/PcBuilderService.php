<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PcBuilderService
{
    private const COMPONENT_TYPES = [
        'processor', 'motherboard', 'memory', 'storage', 'power_supply', 'case',
        'graphics_card', 'cooler', 'fan', 'network_adapter', 'monitor', 'keyboard',
        'mouse', 'headset', 'speakers', 'webcam', 'ups_avr',
    ];

    public function __construct(private readonly CompatibilityEngine $compatibilityEngine) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function catalog(?User $customer = null): array
    {
        $wishlistedProductIds = $customer?->wishlistItems()->pluck('product_id')->flip();

        return Product::query()
            ->customerEligible()
            ->whereHas('componentProfile', fn ($query) => $query->whereIn('component_type', self::COMPONENT_TYPES))
            ->with(['componentProfile.revisions', 'inventory'])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $this->present($product, $wishlistedProductIds?->has($product->id) ?? false))
            ->groupBy('component_type')
            ->all();
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

        if ($products->count() !== count($selection)) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected products are no longer available. Refresh the component list and try again.',
            ]);
        }

        $resolved = [];
        foreach ($selection as $item) {
            /** @var Product $product */
            $product = $products->get($item['product_id']);
            $profile = $product->componentProfile;
            $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);

            if ($profile === null || ! in_array($profile->component_type, self::COMPONENT_TYPES, true)) {
                throw ValidationException::withMessages(['components' => 'A selected product is not configured as a PC Builder component.']);
            }

            $resolved[] = [
                'key' => $item['key'],
                'product_id' => $product->id,
                'component_type' => $profile->component_type,
                'quantity' => $item['quantity'],
                'configuration' => $item['configuration'] ?? [],
                'specifications' => $profile->review_status === 'approved' && $revision?->review_status === 'approved'
                    ? $revision->specifications
                    : [],
            ];
        }

        return $this->compatibilityEngine->evaluate($resolved);
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
            'is_wishlisted' => $isWishlisted,
        ];
    }
}
