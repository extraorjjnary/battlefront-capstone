<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    /**
     * Display the customer product catalog.
     */
    public function index(): Response
    {
        $products = $this->catalogQuery()
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Product $product): array => $this->catalogData($product));

        return Inertia::render('Products/Index', [
            'products' => $products,
        ]);
    }

    /**
     * Display an eligible product using authoritative catalog data.
     */
    public function show(int $product): Response
    {
        $catalogProduct = $this->catalogQuery()->findOrFail($product);

        return Inertia::render('Products/Show', [
            'product' => $this->catalogData($catalogProduct),
        ]);
    }

    /**
     * Build the customer-safe product query with all presentation relationships.
     *
     * @return Builder<Product>
     */
    private function catalogQuery(): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id',
                'name',
                'description',
                'category_id',
                'brand',
                'price',
                'discount_price',
                'image_url',
                'is_featured',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->withExists('lowStockInventory as is_low_stock');
    }

    /**
     * Convert a product into the customer catalog contract.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     brand: string,
     *     price: string,
     *     discount_price: string|null,
     *     image_url: string|null,
     *     is_featured: bool,
     *     category: array{id: int, name: string},
     *     tags: list<array{id: int, name: string}>,
     *     inventory: array{quantity: int|null, status: string}
     * }
     */
    private function catalogData(Product $product): array
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
