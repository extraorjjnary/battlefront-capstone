<?php

namespace App\Services\Recommendation;

use App\Enums\RecommendationIntendedUse;
use App\Models\Product;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnexpectedValueException;

class RecommendationEngine
{
    public function __construct(private readonly ProductCatalogRepository $productCatalogRepository) {}

    /**
     * Rank currently available catalog products against validated customer criteria.
     *
     * @param  array{budget: string, intended_use: RecommendationIntendedUse, preferred_brand: string|null, category_id: int|null, tag_ids: list<int>}  $criteria
     * @return Collection<int, RecommendedProduct>
     */
    public function recommend(array $criteria): Collection
    {
        if (! is_numeric($criteria['budget'])) {
            throw new InvalidArgumentException('Recommendation budget must be numeric.');
        }

        $signals = $criteria['intended_use']->catalogSignals();
        $recommendations = [];

        foreach ($this->productCatalogRepository->recommendationInputs()->orderBy('id')->get() as $product) {
            if ($product->getAttribute('is_available') !== true) {
                continue;
            }

            $effectivePrice = $product->discount_price ?? $product->price;

            if (! is_numeric($effectivePrice)) {
                throw new UnexpectedValueException('Catalog product price must be numeric.');
            }

            if (bccomp($effectivePrice, $criteria['budget'], 2) > 0
                || ($criteria['category_id'] !== null && $product->category_id !== $criteria['category_id'])
                || ! $this->matchesPreferredBrand($product, $criteria['preferred_brand'])) {
                continue;
            }

            $intendedUseReasons = $this->intendedUseReasons($product, $signals);

            if ($intendedUseReasons === []) {
                continue;
            }

            $preferredTagReasons = $this->preferredTagReasons($product, $criteria['tag_ids']);
            $reasons = [
                ['code' => 'within_budget', 'value' => $effectivePrice],
                ['code' => 'sagay_stock', 'value' => 'available'],
                ...$intendedUseReasons,
            ];

            if ($criteria['category_id'] !== null) {
                $reasons[] = ['code' => 'selected_category', 'value' => $product->category->name];
            }

            if ($criteria['preferred_brand'] !== null) {
                $reasons[] = ['code' => 'preferred_brand', 'value' => $product->brand];
            }

            $recommendations[] = new RecommendedProduct(
                product: $product,
                effectivePrice: $effectivePrice,
                intendedUseMatchCount: count($intendedUseReasons),
                preferredTagMatchCount: count($preferredTagReasons),
                reasons: [...$reasons, ...$preferredTagReasons],
            );
        }

        usort($recommendations, static function (RecommendedProduct $left, RecommendedProduct $right): int {
            return ($right->intendedUseMatchCount <=> $left->intendedUseMatchCount)
                ?: ($right->preferredTagMatchCount <=> $left->preferredTagMatchCount)
                ?: bccomp($left->effectivePrice, $right->effectivePrice, 2)
                ?: ($left->product->id <=> $right->product->id);
        });

        return collect($recommendations);
    }

    private function matchesPreferredBrand(Product $product, ?string $preferredBrand): bool
    {
        if ($preferredBrand === null) {
            return true;
        }

        return $product->brand !== null
            && mb_strtolower(trim($product->brand)) === mb_strtolower(trim($preferredBrand));
    }

    /**
     * @param  array{categories: list<string>, tags: list<string>}  $signals
     * @return list<array{code: string, value: string}>
     */
    private function intendedUseReasons(Product $product, array $signals): array
    {
        $reasons = [];

        if (in_array($product->category->name, $signals['categories'], true)) {
            $reasons[] = ['code' => 'intended_use_category', 'value' => $product->category->name];
        }

        foreach ($product->tags->sortBy([['name', 'asc'], ['id', 'asc']]) as $tag) {
            if (in_array($tag->name, $signals['tags'], true)) {
                $reasons[] = ['code' => 'intended_use_tag', 'value' => $tag->name];
            }
        }

        return $reasons;
    }

    /**
     * @param  list<int>  $preferredTagIds
     * @return list<array{code: string, value: string}>
     */
    private function preferredTagReasons(Product $product, array $preferredTagIds): array
    {
        $reasons = [];

        foreach ($product->tags->sortBy([['name', 'asc'], ['id', 'asc']]) as $tag) {
            if (in_array($tag->id, $preferredTagIds, true)) {
                $reasons[] = ['code' => 'preferred_tag', 'value' => $tag->name];
            }
        }

        return $reasons;
    }
}
