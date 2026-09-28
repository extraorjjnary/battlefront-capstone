<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Tag;

class CatalogProductPresenter
{
    /**
     * Convert an eagerly loaded product into the customer catalog contract.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     brand: string|null,
     *     price: string,
     *     discount_price: string|null,
     *     image_url: string|null,
     *     is_featured: bool,
     *     category: array{id: int, name: string},
     *     tags: list<array{id: int, name: string}>,
     *     inventory: array{quantity: int|null, status: string}
     * }
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
