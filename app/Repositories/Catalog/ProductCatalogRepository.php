<?php

namespace App\Repositories\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductCatalogRepository
{
    /**
     * Return customer-eligible products for the catalog directory.
     *
     * @param  array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->catalogQuery($filters)
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
            ->appends(array_filter($filters, fn (mixed $value): bool => $value !== null));
    }

    public function findEligibleOrFail(int $productId): Product
    {
        return $this->catalogQuery()->findOrFail($productId);
    }

    /**
     * Find customer-eligible products whose catalog attributes match every search term.
     *
     * @param  list<string>  $terms
     * @return EloquentCollection<int, Product>
     */
    public function contextMatches(array $terms, int $limit = 5): EloquentCollection
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->where(function (Builder $attributeQuery) use ($term): void {
                        $attributeQuery
                            ->whereLike('name', "%{$term}%")
                            ->orWhereLike('brand', "%{$term}%")
                            ->orWhereLike('description', "%{$term}%")
                            ->orWhereHas(
                                'category',
                                fn (Builder $categoryQuery): Builder => $categoryQuery
                                    ->whereLike('name', "%{$term}%"),
                            )
                            ->orWhereHas(
                                'tags',
                                fn (Builder $tagQuery): Builder => $tagQuery
                                    ->whereLike('name', "%{$term}%"),
                            );
                    });
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{
     *     categories: Collection<int, Category>,
     *     brands: Collection<int, string>,
     *     tags: Collection<int, Tag>
     * }
     */
    public function filterOptions(): array
    {
        return [
            'categories' => Category::query()
                ->active()
                ->whereIn('id', Product::query()->customerEligible()->select('category_id'))
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
     * @param  array{q?: string|null, category_id?: int|null, brand?: string|null, tag_id?: int|null}  $filters
     * @return Builder<Product>
     */
    private function catalogQuery(array $filters = []): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path', 'is_featured',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->withExists([
                'inventory as has_stock' => fn (Builder $query): Builder => $query->where('quantity', '>', 0),
                'lowStockInventory as is_low_stock',
            ])
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
    }
}
