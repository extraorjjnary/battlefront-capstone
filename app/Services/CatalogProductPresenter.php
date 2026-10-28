<?php

namespace App\Services;

use App\Enums\ShippingProfile;
use App\Models\Product;
use App\Models\Tag;
use App\Services\Order\DeliveryRules;

/**
 * @phpstan-type CatalogProductData array{
 *     id: int, name: string, description: string|null, brand: string|null,
 *     price: string, discount_price: string|null, image_url: string|null,
 *     is_featured: bool, shipping_profile: string,
 *     category: array{id: int, name: string},
 *     tags: list<array{id: int, name: string}>,
 *     inventory: array{quantity: int|null, status: string}
 * }
 */
class CatalogProductPresenter
{
    public function __construct(private readonly DeliveryRules $deliveryRules) {}

    /** @return array{profile: string, label: string, surcharge: string, preparation_days: int} */
    public function handling(ShippingProfile $profile): array
    {
        return [
            'profile' => $profile->value,
            'label' => ucfirst($profile->value),
            ...$this->deliveryRules->handling($profile),
        ];
    }

    /** @return list<array{profile: string, label: string, surcharge: string, preparation_days: int}> */
    public function handlingOptions(): array
    {
        return array_map($this->handling(...), ShippingProfile::cases());
    }

    /**
     * Convert an eagerly loaded product into the customer catalog contract.
     *
     * @return CatalogProductData
     */
    public function present(Product $product): array
    {
        $quantity = $product->inventory?->quantity;

        $stockStatus = match (true) {
            $quantity === null => 'unavailable',
            $quantity === 0 => 'out_of_stock',
            (bool) $product->getAttribute('is_low_stock') => 'low_stock',
            default => 'in_stock',
        };

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand,
            'price' => $product->price,
            'discount_price' => $product->discount_price,
            'image_url' => $product->image_url,
            'is_featured' => $product->is_featured,
            'shipping_profile' => $product->shipping_profile->value,
            'category' => [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ],
            'tags' => array_values($product->tags
                ->sortBy([
                    ['name', 'asc'],
                    ['id', 'asc'],
                ])
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->all()),
            'inventory' => [
                'quantity' => $quantity,
                'status' => $stockStatus,
            ],
        ];
    }
}
