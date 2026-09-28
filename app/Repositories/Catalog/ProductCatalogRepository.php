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
     * Provide live catalog facts for deterministic recommendation rules.
     *
     * @return Builder<Product>
     */
    public function recommendationInputs(): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'product_code', 'name', 'category_id', 'brand',
                'price', 'discount_price',
            ])
            ->with([
                'category:id,name',
                'tags:id,name',
                'inventory:id,product_id,quantity',
            ])
            ->withExists([
                'inventory as is_available' => fn (Builder $query): Builder => $query->where('quantity', '>', 0),
            ]);
    }

    /**
     * Return customer-eligible products for the catalog directory.
     *
     * @param  array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->catalogQuery($filters)
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
        $matches = (new Product)->newCollection();

        if ($terms === [] || $limit < 1) {
            return $matches;
        }

        $phrase = implode(' ', $terms);
        $tiers = [
            fn (Builder $query): Builder => $this->whereContextPhrase($query, $phrase, exact: true),
            fn (Builder $query): Builder => $this->whereContextPhrase($query, $phrase),
        ];

        if (count($terms) > 1) {
            $tiers[] = fn (Builder $query): Builder => $this->whereContextTerms($query, $terms);
        }

        $tiers[] = fn (Builder $query): Builder => $this->whereContextTerms($query, $terms, includeDescription: true);

        foreach ($tiers as $tier) {
            if ($matches->count() >= $limit) {
                break;
            }

            $query = $this->contextQuery()
                ->where(fn (Builder $query): Builder => $tier($query))
                ->orderBy('name')
                ->orderBy('id')
                ->limit($limit - $matches->count());

            if ($matches->isNotEmpty()) {
                $query->whereNotIn('products.id', $matches->modelKeys());
            }

            foreach ($query->get() as $product) {
                $matches->push($product);
            }
        }

        return $matches;
    }

    /**
     * @return Builder<Product>
     */
    private function contextQuery(): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level',
                'tags:id,name',
            ]);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function whereContextPhrase(Builder $query, string $phrase, bool $exact = false): Builder
    {
        $pattern = $exact ? $phrase : "%{$phrase}%";

        $query
            ->whereLike('name', $pattern)
            ->orWhereLike('brand', $pattern)
            ->orWhereHas(
                'category',
                fn (Builder $categoryQuery): Builder => $categoryQuery->whereLike('name', $pattern),
            )
            ->orWhereHas(
                'tags',
                fn (Builder $tagQuery): Builder => $tagQuery->whereLike('name', $pattern),
            );

        if ($exact) {
            $query
                ->orWhereLike('name', "[DEMO] {$phrase}")
                ->orWhereHas(
                    'category',
                    fn (Builder $categoryQuery): Builder => $categoryQuery->whereLike('name', "[DEMO] {$phrase}"),
                );
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     * @param  list<string>  $terms
     * @return Builder<Product>
     */
    private function whereContextTerms(Builder $query, array $terms, bool $includeDescription = false): Builder
    {
        foreach ($terms as $term) {
            $query->where(function (Builder $attributeQuery) use ($term, $includeDescription): void {
                $this->whereContextPhrase($attributeQuery, $term);

                if ($includeDescription) {
                    $attributeQuery->orWhereLike('description', "%{$term}%");
                }
            });
        }

        return $query;
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
                ->whereNotNull('brand')
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
            ->withExists('lowStockInventory as is_low_stock')
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
