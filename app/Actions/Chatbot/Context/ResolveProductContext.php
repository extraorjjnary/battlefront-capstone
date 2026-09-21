<?php

namespace App\Actions\Chatbot\Context;

use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Support\Str;

class ResolveProductContext
{
    /** @var list<string> */
    private const QUERY_WORDS = [
        'a',
        'an',
        'any',
        'anything',
        'are',
        'at',
        'available',
        'availability',
        'can',
        'cost',
        'do',
        'does',
        'for',
        'find',
        'has',
        'have',
        'how',
        'i',
        'in',
        'is',
        'item',
        'items',
        'looking',
        'me',
        'much',
        'my',
        'need',
        'of',
        'on',
        'please',
        'price',
        'prices',
        'pricing',
        'product',
        'products',
        'sell',
        'show',
        'something',
        'stock',
        'that',
        'the',
        'this',
        'what',
        'which',
        'with',
        'you',
        'your',
    ];

    public function __construct(private ProductCatalogRepository $products) {}

    /**
     * Resolve authoritative catalog and Sagay inventory facts for a product inquiry.
     *
     * @return array{products: list<array{
     *     name: string,
     *     description: string|null,
     *     brand: string,
     *     category: string,
     *     tags: list<string>,
     *     price: string,
     *     discount_price: string|null,
     *     inventory: array{quantity: int|null, status: 'unavailable'|'out_of_stock'|'in_stock'}
     * }>}
     */
    public function execute(string $message): array
    {
        $terms = $this->meaningfulTerms($message);

        if ($terms === []) {
            return ['products' => []];
        }

        return [
            'products' => array_values($this->products
                ->contextMatches($terms)
                ->map(fn (Product $product): array => $this->mapProduct($product))
                ->all()),
        ];
    }

    /**
     * @return list<string>
     */
    private function meaningfulTerms(string $message): array
    {
        $normalizedMessage = Str::of($message)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        if ($normalizedMessage === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalizedMessage),
            fn (string $term): bool => Str::length($term) >= 2
                && ! in_array($term, self::QUERY_WORDS, strict: true),
        )));
    }

    /**
     * @return array{
     *     name: string,
     *     description: string|null,
     *     brand: string,
     *     category: string,
     *     tags: list<string>,
     *     price: string,
     *     discount_price: string|null,
     *     inventory: array{quantity: int|null, status: 'unavailable'|'out_of_stock'|'in_stock'}
     * }
     */
    private function mapProduct(Product $product): array
    {
        $quantity = $product->inventory?->quantity;

        return [
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand,
            'category' => $product->category->name,
            'tags' => array_values($product->tags
                ->map(fn (Tag $tag): string => $tag->name)
                ->sort()
                ->all()),
            'price' => $product->price,
            'discount_price' => $product->discount_price,
            'inventory' => [
                'quantity' => $quantity,
                'status' => match (true) {
                    $quantity === null => 'unavailable',
                    $quantity === 0 => 'out_of_stock',
                    default => 'in_stock',
                },
            ],
        ];
    }
}
