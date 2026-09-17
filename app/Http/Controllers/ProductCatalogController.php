<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    /**
     * Display the customer product catalog.
     */
    public function index(ProductCatalogIndexRequest $request): Response
    {
        $filters = $this->catalogFilters($request);

        $products = $this->catalogQuery($filters)
            ->orderByRaw(<<<'SQL'
                CASE
                    WHEN has_stock = 0 THEN 3
                    WHEN is_low_stock = 1 THEN 2
                    ELSE 1
                END
                SQL)
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(12)
            ->appends(array_filter($filters, fn (mixed $value): bool => $value !== null))
            ->through(fn (Product $product): array => $this->catalogData($product));

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
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
     * @param  array{q?: string|null, category_id?: int|null, brand?: string|null, tag_id?: int|null}  $filters
     * @return Builder<Product>
     */
    private function catalogQuery(array $filters = []): Builder
    {
        $query = Product::query()
            ->customerEligible()
            ->select([
                'id',
                'name',
                'description',
                'category_id',
                'brand',
                'price',
                'discount_price',
                'image_path',
                'is_featured',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->withExists([
                'inventory as has_stock' => fn (Builder $query): Builder => $query->where('quantity', '>', 0),
                'lowStockInventory as is_low_stock',
            ]);

        $query
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('brand', "%{$search}%")
                        ->orWhereLike('description', "%{$search}%");
                });
            })
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $filters['brand'] ?? null,
                fn (Builder $query, string $brand): Builder => $query->where('brand', $brand),
            )
            ->when(
                $filters['tag_id'] ?? null,
                fn (Builder $query, int $tagId): Builder => $query->whereHas(
                    'tags',
                    fn (Builder $query): Builder => $query->whereKey($tagId),
                ),
            );

        return $query;
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
     * Get filter choices represented by customer-eligible catalog records.
     *
     * @return array{
     *     categories: Collection<int, Category>,
     *     brands: Collection<int, string>,
     *     tags: Collection<int, Tag>
     * }
     */
    private function filterOptions(): array
    {
        return [
            'categories' => Category::query()
                ->active()
                ->whereIn(
                    'id',
                    Product::query()->customerEligible()->select('category_id'),
                )
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'brands' => Product::query()
                ->customerEligible()
                ->select('brand')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand'),
            'tags' => Tag::query()
                ->whereHas('products', fn (Builder $query): Builder => $query->whereIn(
                    'products.id',
                    Product::query()->customerEligible()->select('id'),
                ))
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ];
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
