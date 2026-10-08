<?php

namespace App\Services\Recommendation;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\GuestRecommendationProfile;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BehavioralRecommendationEngine
{
    private const CANDIDATE_LIMIT = 100;

    private const SEARCH_HISTORY_LIMIT = 5;

    private const SEARCH_MATCH_LIMIT = 12;

    private const PRODUCT_VIEW_HISTORY_LIMIT = 5;

    public function __construct(private readonly ProductCatalogRepository $productCatalogRepository) {}

    /**
     * Recommend available products using recent customer activity and completed orders.
     *
     * Score weights are intentionally simple: a fresh search starts at 55 points, a
     * cart companion at 38, a viewed-product match at up to 48 plus dwell time, purchase
     * relationships at 15, and popularity contributes at most 5 points as a tie-breaker.
     *
     * @return Collection<int, BehavioralRecommendedProduct>
     */
    public function recommendFor(User|GuestRecommendationProfile $customer, int $limit = 12): Collection
    {
        if ($customer instanceof User && ! $customer->personalized_recommendations_enabled) {
            return $this->popular($limit);
        }

        $purchasedProductIds = $customer instanceof User ? OrderItem::query()
            ->whereHas('order', fn (Builder $query): Builder => $query
                ->whereBelongsTo($customer)
                ->where('status', OrderStatus::Completed->value))
            ->distinct()
            ->pluck('product_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all() : [];

        $cartProductIds = $customer instanceof User ? CartItem::query()
            ->whereHas('cart', fn (Builder $query): Builder => $query->whereBelongsTo($customer))
            ->pluck('product_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all() : [];

        $viewedProductCounts = $this->recentlyViewedProductCounts($customer);
        $viewedProductIds = array_keys($viewedProductCounts);
        $excludedProductIds = array_values(array_unique([
            ...$purchasedProductIds,
            ...$cartProductIds,
            ...$viewedProductIds,
        ]));
        $scores = [];
        $reasons = [];

        if ($customer instanceof GuestRecommendationProfile || $customer->search_recommendations_enabled) {
            $this->addSearchCandidates($customer, $excludedProductIds, $scores, $reasons);
        }

        if ($cartProductIds !== []) {
            $this->addCountCandidates(
                $this->coPurchaseCounts($cartProductIds, $excludedProductIds),
                $scores,
                $reasons,
                baseScore: 38,
                countWeight: 2,
                countCap: 8,
                reason: ['code' => 'bought_with_cart_products', 'value' => 'Often purchased with products in your cart'],
            );
        }

        if ($viewedProductIds !== []) {
            $this->addViewedSimilarityCandidates($viewedProductCounts, $excludedProductIds, $scores, $reasons);
            $this->addCountCandidates(
                $this->coPurchaseCounts($viewedProductIds, $excludedProductIds),
                $scores,
                $reasons,
                baseScore: 18,
                countWeight: 1,
                countCap: 5,
                reason: ['code' => 'bought_with_viewed_products', 'value' => 'Often purchased with products you viewed'],
            );
        }

        if ($purchasedProductIds !== []) {
            $this->addCountCandidates(
                $this->coPurchaseCounts($purchasedProductIds, $excludedProductIds),
                $scores,
                $reasons,
                baseScore: 18,
                countWeight: 1,
                countCap: 8,
                reason: ['code' => 'bought_with_purchase_history', 'value' => 'Often purchased with products you bought'],
            );

            if ($customer instanceof User) {
                $this->addSimilarCustomerCandidates(
                    $customer->getKey(),
                    $purchasedProductIds,
                    $excludedProductIds,
                    $scores,
                    $reasons,
                );
            }
        }

        $this->addPopularityCandidates($excludedProductIds, $scores, $reasons);

        if (count($scores) < $limit) {
            foreach ($this->productCatalogRepository->featuredFallback($excludedProductIds, self::CANDIDATE_LIMIT) as $product) {
                $scores[$product->id] ??= 0.1;
                $reasons[$product->id] ??= [[
                    'code' => 'featured_fallback',
                    'value' => 'Featured and currently available',
                ]];
            }
        }

        return $this->buildRankedRecommendations($scores, $reasons, $limit);
    }

    /**
     * Recommend popular products to visitors without a customer account.
     *
     * @return Collection<int, BehavioralRecommendedProduct>
     */
    public function popular(int $limit = 12): Collection
    {
        $scores = [];
        $reasons = [];
        $this->addPopularityCandidates([], $scores, $reasons);

        if (count($scores) < $limit) {
            foreach ($this->productCatalogRepository->featuredFallback([], self::CANDIDATE_LIMIT) as $product) {
                $scores[$product->id] ??= 0.1;
                $reasons[$product->id] ??= [[
                    'code' => 'featured_fallback',
                    'value' => 'Featured and currently available',
                ]];
            }
        }

        return $this->buildRankedRecommendations($scores, $reasons, $limit);
    }

    /**
     * @param  array<int, int>  $excludedProductIds
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     */
    private function addSearchCandidates(User|GuestRecommendationProfile $customer, array $excludedProductIds, array &$scores, array &$reasons): void
    {
        $searches = $customer->searches()
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->limit(self::SEARCH_HISTORY_LIMIT)
            ->get(['query', 'created_at']);

        foreach ($searches as $index => $search) {
            $terms = preg_split('/\\s+/u', trim($search->query), -1, PREG_SPLIT_NO_EMPTY);
            $terms = array_values(array_filter(
                $terms ?: [],
                static fn (string $term): bool => mb_strlen($term) >= 2
                    && preg_match('/^[\\pL\\pN-]+$/u', $term) === 1,
            ));

            if ($terms === []) {
                continue;
            }

            $ageHours = max(0, $search->created_at->diffInHours(now()));
            $recencyScore = 55 / (1 + ($ageHours / 24)) / (1 + ($index * 0.1));

            foreach ($this->productCatalogRepository->contextMatches($terms, self::SEARCH_MATCH_LIMIT) as $product) {
                if (in_array($product->id, $excludedProductIds, true)) {
                    continue;
                }

                $scores[$product->id] = ($scores[$product->id] ?? 0) + $recencyScore;
                $this->appendReason($reasons, $product->id, [
                    'code' => 'matched_recent_searches',
                    'value' => 'Matches a recent catalog search',
                ]);
            }
        }
    }

    /**
     * Add candidates similar to viewed products based on shared catalog attributes and price.
     *
     * @param  array<int, array{count: int, dwell_seconds: int}>  $viewedProductCounts
     * @param  array<int, int>  $excludedProductIds
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     */
    private function addViewedSimilarityCandidates(array $viewedProductCounts, array $excludedProductIds, array &$scores, array &$reasons): void
    {
        $viewedProductIds = array_keys($viewedProductCounts);
        $anchors = $this->productCatalogRepository->recommendationInputs()
            ->whereKey($viewedProductIds)
            ->get()
            ->keyBy('id');

        foreach ($this->productCatalogRepository->similarProducts($viewedProductIds, $excludedProductIds) as $candidate) {
            $bestSimilarityScore = 0;
            $hasDwellBasedMatch = false;

            foreach ($anchors as $anchor) {
                $categoryMatch = $candidate->category_id === $anchor->category_id;
                $brandMatch = $candidate->brand !== null
                    && $anchor->brand !== null
                    && mb_strtolower($candidate->brand) === mb_strtolower($anchor->brand);
                $sharedTags = $candidate->tags->modelKeys()
                    ? array_intersect($candidate->tags->modelKeys(), $anchor->tags->modelKeys())
                    : [];

                if (! $categoryMatch && ! $brandMatch && $sharedTags === []) {
                    continue;
                }

                $anchorPrice = (float) ($anchor->discount_price ?? $anchor->price);
                $candidatePrice = (float) ($candidate->discount_price ?? $candidate->price);
                $priceRatio = $anchorPrice > 0 ? $candidatePrice / $anchorPrice : 0;

                if ($priceRatio < 0.5 || $priceRatio > 2) {
                    continue;
                }

                $attributeScore = ($categoryMatch ? 14 : 0)
                    + ($brandMatch ? 6 : 0)
                    + min(count($sharedTags) * 4, 8);
                $viewSignal = $viewedProductCounts[$anchor->id] ?? ['count' => 1, 'dwell_seconds' => 0];
                $deliberateViewBoost = 1 + min(log(max($viewSignal['count'], 1), 2) * 0.25, 0.5);
                $dwellBoost = $viewSignal['dwell_seconds'] >= 5
                    ? min(log(($viewSignal['dwell_seconds'] / 5) + 1, 2) * 3, 8)
                    : 0;
                $similarityScore = ($attributeScore + 4) * $deliberateViewBoost + $dwellBoost;
                $bestSimilarityScore = max($bestSimilarityScore, $similarityScore);
                $hasDwellBasedMatch = $hasDwellBasedMatch || $dwellBoost > 0;
            }

            if ($bestSimilarityScore < 1) {
                continue;
            }

            $scores[$candidate->id] = ($scores[$candidate->id] ?? 0) + $bestSimilarityScore;
            $this->appendReason($reasons, $candidate->id, [
                'code' => 'similar_to_viewed_product',
                'value' => 'Similar to a product you viewed',
            ]);

            if ($hasDwellBasedMatch) {
                $this->appendReason($reasons, $candidate->id, [
                    'code' => 'spent_time_viewing_product',
                    'value' => 'You spent time viewing a similar product',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, int>  $counts
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     * @param  array{code: string, value: string}  $reason
     */
    private function addCountCandidates(
        Collection $counts,
        array &$scores,
        array &$reasons,
        float $baseScore,
        float $countWeight,
        float $countCap,
        array $reason,
    ): void {
        foreach ($counts as $productId => $count) {
            $scores[$productId] = ($scores[$productId] ?? 0) + $baseScore + min($count * $countWeight, $countCap);
            $this->appendReason($reasons, (int) $productId, $reason);
        }
    }

    /**
     * @param  array<int, int>  $excludedProductIds
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     */
    private function addPopularityCandidates(array $excludedProductIds, array &$scores, array &$reasons): void
    {
        foreach ($this->popularCounts($excludedProductIds) as $productId => $count) {
            $scores[$productId] = ($scores[$productId] ?? 0) + min(log($count + 1, 2) * 2, 5);
            $this->appendReason($reasons, (int) $productId, [
                'code' => 'popular_with_customers',
                'value' => 'Popular with Battlefront customers',
            ]);
        }
    }

    /**
     * Add products bought by customers whose completed orders overlap with this customer's history.
     *
     * @param  array<int, int>  $purchasedProductIds
     * @param  array<int, int>  $excludedProductIds
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     */
    private function addSimilarCustomerCandidates(
        int $customerId,
        array $purchasedProductIds,
        array $excludedProductIds,
        array &$scores,
        array &$reasons,
    ): void {
        $similarCustomerIds = OrderItem::query()
            ->join('orders as anchor_orders', 'anchor_orders.id', '=', 'order_items.order_id')
            ->join('order_items as similar_items', 'similar_items.product_id', '=', 'order_items.product_id')
            ->join('orders as similar_orders', 'similar_orders.id', '=', 'similar_items.order_id')
            ->where('anchor_orders.user_id', $customerId)
            ->where('anchor_orders.status', OrderStatus::Completed->value)
            ->where('similar_orders.user_id', '!=', $customerId)
            ->where('similar_orders.status', OrderStatus::Completed->value)
            ->whereIn('order_items.product_id', $purchasedProductIds)
            ->select('similar_orders.user_id')
            ->selectRaw('COUNT(DISTINCT order_items.product_id) as shared_product_count')
            ->groupBy('similar_orders.user_id')
            ->orderByDesc('shared_product_count')
            ->limit(20)
            ->pluck('similar_orders.user_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        if ($similarCustomerIds === []) {
            return;
        }

        $candidateCounts = OrderItem::query()
            ->join('orders as buyer_orders', 'buyer_orders.id', '=', 'order_items.order_id')
            ->whereIn('buyer_orders.user_id', $similarCustomerIds)
            ->where('buyer_orders.status', OrderStatus::Completed->value)
            ->whereNotIn('order_items.product_id', $excludedProductIds)
            ->whereIn('order_items.product_id', Product::query()->cartEligible()->select('id'))
            ->select('order_items.product_id')
            ->selectRaw('COUNT(DISTINCT buyer_orders.user_id) as similar_customer_count')
            ->groupBy('order_items.product_id')
            ->orderByDesc('similar_customer_count')
            ->orderBy('order_items.product_id')
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('similar_customer_count', 'order_items.product_id');

        foreach ($candidateCounts as $productId => $similarCustomerCount) {
            $productId = (int) $productId;
            $scores[$productId] = ($scores[$productId] ?? 0) + 24 + min((int) $similarCustomerCount * 3, 15);
            $this->appendReason($reasons, $productId, [
                'code' => 'bought_by_similar_customers',
                'value' => 'Bought by customers with overlapping purchase histories',
            ]);
        }
    }

    /**
     * @param  array<int, float>  $scores
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     * @return Collection<int, BehavioralRecommendedProduct>
     */
    private function buildRankedRecommendations(array $scores, array $reasons, int $limit): Collection
    {
        if ($scores === [] || $limit < 1) {
            return collect();
        }

        $productIds = array_map('intval', array_keys($scores));
        $products = $this->productCatalogRepository->recommendationInputs()
            ->whereKey($productIds)
            ->get()
            ->filter(static fn (Product $product): bool => $product->getAttribute('is_available') === true)
            ->keyBy('id');

        usort($productIds, static fn (int $left, int $right): int => ($scores[$right] <=> $scores[$left]) ?: ($left <=> $right));
        $productIds = array_slice($productIds, 0, self::CANDIDATE_LIMIT);

        $categoryLimit = max(1, (int) ceil($limit / 3));
        $categoryCounts = [];
        $diverseProductIds = [];
        $deferredProductIds = [];

        foreach ($productIds as $productId) {
            if (! $products->has($productId)) {
                continue;
            }

            $categoryId = $products->get($productId)->category_id;

            if (($categoryCounts[$categoryId] ?? 0) >= $categoryLimit) {
                $deferredProductIds[] = $productId;

                continue;
            }

            $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
            $diverseProductIds[] = $productId;
        }

        $productIds = [...$diverseProductIds, ...$deferredProductIds];

        return collect($productIds)
            ->filter(fn (int $productId): bool => $products->has($productId))
            ->take($limit)
            ->map(function (int $productId) use ($products, $reasons): BehavioralRecommendedProduct {
                /** @var Product $product */
                $product = $products->get($productId);

                return new BehavioralRecommendedProduct(
                    product: $product,
                    effectivePrice: bcadd($product->discount_price ?? $product->price, '0', 2),
                    reasons: $reasons[$productId] ?? [[
                        'code' => 'popular_with_customers',
                        'value' => 'Popular with Battlefront customers',
                    ]],
                );
            })
            ->values();
    }

    /**
     * @param  array<int, list<array{code: string, value: string}>>  $reasons
     * @param  array{code: string, value: string}  $reason
     */
    private function appendReason(array &$reasons, int $productId, array $reason): void
    {
        $reasons[$productId] ??= [];

        if (! in_array($reason, $reasons[$productId], true)) {
            $reasons[$productId][] = $reason;
        }
    }

    /**
     * Get the customer's five most recently viewed products and their deliberate view counts.
     *
     * @return array<int, array{count: int, dwell_seconds: int}> product ID to recent view count and dwell time
     */
    private function recentlyViewedProductCounts(User|GuestRecommendationProfile $customer): array
    {
        if ($customer instanceof User && ! $customer->product_view_recommendations_enabled) {
            return [];
        }

        $recentViews = $customer->productViews()
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->limit(self::PRODUCT_VIEW_HISTORY_LIMIT * 4)
            ->get(['product_id', 'dwell_seconds']);
        $counts = [];

        foreach ($recentViews as $view) {
            $productId = (int) $view->product_id;
            $counts[$productId] ??= ['count' => 0, 'dwell_seconds' => 0];
            $counts[$productId]['count']++;
            $counts[$productId]['dwell_seconds'] += (int) $view->dwell_seconds;
        }

        return array_slice($counts, 0, self::PRODUCT_VIEW_HISTORY_LIMIT, true);
    }

    /**
     * @param  array<int, int>  $anchorProductIds
     * @param  array<int, int>  $excludedProductIds
     * @return Collection<int, int>
     */
    private function coPurchaseCounts(array $anchorProductIds, array $excludedProductIds): Collection
    {
        if ($anchorProductIds === []) {
            return collect();
        }

        return OrderItem::query()
            ->select('product_id')
            ->selectRaw('COUNT(DISTINCT order_id) as recommendation_count')
            ->whereNotIn('product_id', $excludedProductIds)
            ->whereIn('product_id', Product::query()->cartEligible()->select('id'))
            ->whereHas('order', fn (Builder $query): Builder => $query->where('status', OrderStatus::Completed->value))
            ->whereHas('order.items', fn (Builder $query): Builder => $query->whereIn('product_id', $anchorProductIds))
            ->groupBy('product_id')
            ->orderByDesc('recommendation_count')
            ->orderBy('product_id')
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('recommendation_count', 'product_id')
            ->map(static fn (int|string $count): int => (int) $count);
    }

    /**
     * @param  array<int, int>  $excludedProductIds
     * @return Collection<int, int>
     */
    private function popularCounts(array $excludedProductIds): Collection
    {
        return OrderItem::query()
            ->select('product_id')
            ->selectRaw('COUNT(DISTINCT order_id) as recommendation_count')
            ->whereNotIn('product_id', $excludedProductIds)
            ->whereIn('product_id', Product::query()->cartEligible()->select('id'))
            ->whereHas('order', fn (Builder $query): Builder => $query->where('status', OrderStatus::Completed->value))
            ->groupBy('product_id')
            ->orderByDesc('recommendation_count')
            ->orderBy('product_id')
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('recommendation_count', 'product_id')
            ->map(static fn (int|string $count): int => (int) $count);
    }
}
