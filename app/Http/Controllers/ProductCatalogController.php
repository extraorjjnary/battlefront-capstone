<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function __construct(private readonly ProductCatalogRepository $productCatalogRepository) {}

    /**
     * Display the customer product catalog.
     */
    public function index(ProductCatalogIndexRequest $request): Response
    {
        $filters = $this->catalogFilters($request);

        $products = $this->productCatalogRepository->paginate($filters)
            ->through(fn (Product $product): array => $this->catalogData($product));

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'filter_options' => $this->productCatalogRepository->filterOptions(),
        ]);
    }

    /**
     * Display an eligible product using authoritative catalog data.
     */
    public function show(int $product): Response
    {
        $catalogProduct = $this->productCatalogRepository->findEligibleOrFail($product);

        return Inertia::render('Products/Show', [
            'product' => $this->catalogData($catalogProduct),
        ]);
    }

    /**
     * Convert validated catalog input into stable Inertia filter values.
     *
     * @return array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null}
     */
    private function catalogFilters(ProductCatalogIndexRequest $request): array
    {
        return [
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'brand' => $request->filled('brand') ? $request->string('brand')->toString() : null,
            'tag_id' => $request->filled('tag_id') ? $request->integer('tag_id') : null,
        ];
    }

    /**
     * Convert a product into the customer catalog contract.
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
