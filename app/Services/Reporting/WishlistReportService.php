<?php

namespace App\Services\Reporting;

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Reporting\WishlistReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

class WishlistReportService
{
    public function __construct(private readonly WishlistReportRepository $repository) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function generate(array $filters): array
    {
        $timezone = config('battlefront.timezone');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone);
        $comparisonDays = $to->diffInDays($from) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($comparisonDays - 1);
        $dailyRows = $this->repository->dailyActivity($from, $to, $filters);
        $previousDailyRows = $this->repository->dailyActivity($previousFrom, $previousTo, $filters);
        $currentTotals = $this->sumActivity($dailyRows);
        $previousTotals = $this->sumActivity($previousDailyRows);
        $coverageStart = $this->repository->activityCoverageStart();
        $coverageComplete = $coverageStart !== null && $previousFrom->greaterThanOrEqualTo($coverageStart);
        $trends = $this->bucketActivity($dailyRows, $from, $to, $filters['period']);
        $products = $this->repository->products($filters);
        $products->through(fn (Product $product): array => $this->productData($product, $filters, $coverageComplete));
        $insights = $coverageComplete ? array_values(collect($this->repository->insightProducts($filters, 'all', 'interest', increasing: true))
            ->map(fn (Product $product): array => $this->productData($product, $filters, $coverageComplete))
            ->all()) : [];
        $outOfStockDemand = $this->stockDemand($filters, 'out_of_stock', $coverageComplete);
        $lowStockDemand = $this->stockDemand($filters, 'low_stock', $coverageComplete);
        $stockDemand = array_values(collect([...$outOfStockDemand, ...$lowStockDemand])->filter(
            static fn (array $product): bool => in_array($product['availability'], ['out_of_stock', 'low_stock'], true)
                && $product['wishlist_customers'] >= 1,
        )->all());

        return [
            'overview' => $this->repository->overview($filters),
            'category_options' => $this->repository->categoryOptions(),
            'filters' => $filters,
            'products' => $products,
            'activity' => [
                ...$currentTotals,
                'net' => $currentTotals['additions'] - $currentTotals['removals'],
                'daily' => $trends,
                'previous' => $previousTotals,
            ],
            'stock_demand' => [
                'out_of_stock' => $outOfStockDemand,
                'low_stock' => $lowStockDemand,
            ],
            'increasing_interest' => $insights,
            'coverage' => [
                'from' => $coverageStart?->toDateString(),
                'complete_for_period' => $coverageComplete,
                'message' => $coverageStart === null
                    ? 'No wishlist activity has been recorded yet.'
                    : ($coverageComplete ? null : 'Activity history does not cover the selected period and its comparison period, which begins '.$previousFrom->toDateString().'.'),
            ],
            'conversion' => [
                'wishlist_to_cart' => null,
                'wishlist_to_purchase' => null,
                'message' => 'Conversion is unavailable because cart and order records do not preserve wishlist attribution.',
            ],
            'thresholds' => [
                'minimum_saves' => $filters['minimum_saves'],
                'minimum_net_additions' => $filters['minimum_net_additions'],
                'minimum_increase_percent' => $filters['minimum_increase_percent'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardSummary(): array
    {
        return $this->repository->overview();
    }

    /** @param array<string, mixed> $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function exportProducts(array $filters): LazyCollection
    {
        $coverageStart = $this->repository->activityCoverageStart();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], config('battlefront.timezone'));
        $coverageComplete = $coverageStart !== null && $from->greaterThanOrEqualTo($coverageStart);

        return $this->repository->exportProducts($filters)->map(fn (Product $product): array => $this->productData($product, $filters, $coverageComplete));
    }

    /** @param array<string, mixed> $filters
     * @return list<array{date:string,additions:int,removals:int}>
     */
    public function exportActivity(array $filters): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], config('battlefront.timezone'));
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], config('battlefront.timezone'));

        return $this->bucketActivity($this->repository->dailyActivity($from, $to, $filters), $from, $to, $filters['period']);
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function stockDemand(array $filters, string $stock, bool $coverageComplete): array
    {
        return array_values(collect($this->repository->insightProducts($filters, $stock, 'customers'))
            ->map(fn (Product $product): array => $this->productData($product, $filters, $coverageComplete))
            ->take(5)
            ->all());
    }

    /** @param list<array{date:string, additions:int, removals:int}> $rows
     * @return array{additions:int, removals:int}
     */
    private function sumActivity(array $rows): array
    {
        return [
            'additions' => array_sum(array_column($rows, 'additions')),
            'removals' => array_sum(array_column($rows, 'removals')),
        ];
    }

    /** @param list<array{date:string, additions:int, removals:int}> $rows
     * @return list<array{date:string, additions:int, removals:int}>
     */
    private function bucketActivity(array $rows, CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $daily = collect($rows)->keyBy('date');
        $buckets = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $bucket = match ($period) {
                'week' => $date->startOfWeek()->toDateString(),
                'month' => $date->startOfMonth()->toDateString(),
                default => $date->toDateString(),
            };
            $row = $daily->get($date->toDateString());
            $buckets[$bucket] ??= ['date' => $bucket, 'additions' => 0, 'removals' => 0];
            $buckets[$bucket]['additions'] += $row['additions'] ?? 0;
            $buckets[$bucket]['removals'] += $row['removals'] ?? 0;
        }

        return array_values($buckets);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function productData(Product $product, array $filters, bool $coverageComplete): array
    {
        $quantity = $product->getAttribute('wishlist_stock_quantity');
        $quantity = $quantity === null ? null : (int) $quantity;
        $reorderLevel = $product->getAttribute('wishlist_reorder_level');
        $availability = $quantity === null
            ? 'unavailable'
            : ($quantity === 0 ? 'out_of_stock' : ($quantity <= (int) $reorderLevel ? 'low_stock' : 'in_stock'));
        $additions = (int) $product->getAttribute('period_additions');
        $removals = (int) $product->getAttribute('period_removals');
        $previousNet = (int) $product->getAttribute('previous_additions') - (int) $product->getAttribute('previous_removals');
        $net = $additions - $removals;
        $increasePercent = $previousNet > 0
            ? (int) round((($net - $previousNet) / $previousNet) * 100)
            : null;
        $isIncreasing = $coverageComplete
            && $previousNet > 0
            && (int) $product->getAttribute('wishlist_customers') >= $filters['minimum_saves']
            && $net >= $filters['minimum_net_additions']
            && $increasePercent !== null
            && $increasePercent >= $filters['minimum_increase_percent'];
        $category = $product->getRelationValue('category');
        $isCurrentProduct = ! $product->trashed()
            && $product->is_active
            && $category instanceof Category
            && ! $category->trashed()
            && $category->is_active;

        return [
            'id' => $product->id,
            'name' => $product->trashed() ? 'Archived product' : $product->name,
            'image_url' => $product->trashed() ? null : $product->image_url,
            'category' => $category instanceof Category ? $category->name : null,
            'is_active' => $isCurrentProduct,
            'price' => $product->price,
            'discount_price' => $product->discount_price,
            'wishlist_customers' => (int) $product->getAttribute('wishlist_customers'),
            'stock_quantity' => $quantity,
            'reorder_level' => $reorderLevel === null ? null : (int) $reorderLevel,
            'availability' => $availability,
            'activity' => [
                'additions' => $additions,
                'removals' => $removals,
                'net' => $net,
                'previous_net' => $previousNet,
            ],
            'demand' => [
                'is_increasing' => $isIncreasing,
                'increase_percent' => $increasePercent,
                'meets_minimum_saves' => (int) $product->getAttribute('wishlist_customers') >= $filters['minimum_saves'],
            ],
        ];
    }
}
