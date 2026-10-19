<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\SalesReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * @phpstan-type SalesFilters array{from: string, to: string, period: string, product_id: ?int, category_id: ?int}
 * @phpstan-type ActiveFilters array{product: ?array{id: int, name: string}, category: ?array{id: int, name: string}}
 * @phpstan-type ProductRow array{id: int, name: string, category: string, sales_count: int, quantity_sold: int, item_revenue: string}
 * @phpstan-type CategoryRow array{id: int, name: string, sales_count: int, quantity_sold: int, item_revenue: string}
 * @phpstan-type Kpis array{total_sales: int, total_revenue: string, average_order_value: string, total_items_sold: int}
 * @phpstan-type Timeline array{labels: list<string>, sales: list<int>, revenue: list<float>}
 */
class SalesReportService
{
    public function __construct(private readonly SalesReportRepository $salesReportRepository) {}

    /**
     * Build the complete sales-report response payload.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(array $filters): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $from = CarbonImmutable::parse($normalizedFilters['from']);
        $to = CarbonImmutable::parse($normalizedFilters['to']);
        $period = $normalizedFilters['period'];
        $productId = $normalizedFilters['product_id'];
        $categoryId = $normalizedFilters['category_id'];
        $dateRange = $this->dateRange($normalizedFilters);
        $salesSummary = $this->salesReportRepository->summary($dateRange, $productId, $categoryId);
        $periodDays = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($periodDays - 1);
        $previousSummary = $this->salesReportRepository->summary(
            [$previousFrom->toDateString(), $previousTo->addDay()->toDateString()],
            $productId,
            $categoryId,
        );
        $dailySales = $this->salesReportRepository->dailySales($dateRange, $productId, $categoryId);
        $topProducts = $this->salesReportRepository->topProducts($dateRange, $categoryId);

        if (
            $productId !== null
            && ! $topProducts->contains(fn (stdClass $product): bool => (int) $product->id === $productId)
        ) {
            $selectedProduct = $this->salesReportRepository->selectedProduct($dateRange, $productId, $categoryId);

            if ($selectedProduct !== null) {
                $topProducts->push($selectedProduct);
            }
        }

        $products = $this->salesReportRepository
            ->products($dateRange, $productId, $categoryId)
            ->appends($normalizedFilters)
            ->through($this->productData(...));
        $categoryChart = $this->salesReportRepository->categories($dateRange, $productId);
        $categoryReport = $this->salesReportRepository->categories($dateRange, $productId, $categoryId);

        return [
            'filters' => $normalizedFilters,
            'active_filters' => $this->activeFilters($normalizedFilters),
            'kpis' => $this->kpiData($salesSummary),
            'comparison' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'total_revenue' => (string) $previousSummary->total_revenue,
                'total_sales' => (int) $previousSummary->total_sales,
            ],
            'sale_transactions' => $this->salesReportRepository->transactions($dateRange, $productId, $categoryId),
            'timeline' => $this->timelineData(array_values($dailySales->all()), $from, $to, $period),
            'top_products' => [
                'ids' => $topProducts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'labels' => $topProducts->pluck('name')->all(),
                'quantities' => $topProducts->pluck('quantity_sold')
                    ->map(fn (mixed $quantity): int => (int) $quantity)->all(),
            ],
            'categories' => [
                'ids' => $categoryChart->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'labels' => $categoryChart->pluck('name')->all(),
                'revenue' => $categoryChart->pluck('item_revenue')
                    ->map(fn (mixed $revenue): float => (float) $revenue)->all(),
                'rows' => $categoryReport->map($this->categoryData(...))->all(),
            ],
            'products' => $products,
        ];
    }

    /**
     * Prepare CSV metadata and lazy product rows without querying dashboard charts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: SalesFilters, active_filters: ActiveFilters, products: LazyCollection<int, ProductRow>}
     */
    public function exportProducts(array $filters): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);

        return [
            'filters' => $normalizedFilters,
            'active_filters' => $this->activeFilters($normalizedFilters),
            'products' => $this->salesReportRepository->exportProducts(
                $this->dateRange($normalizedFilters),
                $normalizedFilters['product_id'],
                $normalizedFilters['category_id'],
            )->map($this->productData(...)),
        ];
    }

    /**
     * Build the common print/PDF document from the authoritative aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: SalesFilters, active_filters: ActiveFilters, products: LazyCollection<int, ProductRow>, kpis: Kpis, timeline: Timeline, categories: list<CategoryRow>}
     */
    public function document(array $filters): array
    {
        $report = $this->exportProducts($filters);
        $scope = $report['filters'];
        $dateRange = $this->dateRange($scope);
        $dailySales = $this->salesReportRepository->dailySales($dateRange, $scope['product_id'], $scope['category_id']);

        return [
            ...$report,
            'kpis' => $this->kpiData($this->salesReportRepository->summary($dateRange, $scope['product_id'], $scope['category_id'])),
            'timeline' => $this->timelineData(
                array_values($dailySales->all()),
                CarbonImmutable::parse($scope['from']),
                CarbonImmutable::parse($scope['to']),
                $scope['period'],
                includeYear: true,
            ),
            'categories' => array_values($this->salesReportRepository->categories($dateRange, $scope['product_id'], $scope['category_id'])
                ->map($this->categoryData(...))->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return SalesFilters
     */
    private function normalizeFilters(array $filters): array
    {
        $timezone = config('battlefront.timezone');
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone) : CarbonImmutable::today($timezone);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone) : $to->subDays(29);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => $filters['period'] ?? 'day',
            'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
            'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
        ];
    }

    /**
     * @param  SalesFilters  $filters
     * @return array{0: string, 1: string}
     */
    private function dateRange(array $filters): array
    {
        return [$filters['from'], CarbonImmutable::parse($filters['to'], config('battlefront.timezone'))->addDay()->toDateString()];
    }

    /**
     * @param  SalesFilters  $filters
     * @return ActiveFilters
     */
    private function activeFilters(array $filters): array
    {
        return [
            'product' => $filters['product_id'] === null ? null : [
                'id' => $filters['product_id'],
                'name' => (string) $this->salesReportRepository->productName($filters['product_id']),
            ],
            'category' => $filters['category_id'] === null ? null : [
                'id' => $filters['category_id'],
                'name' => (string) $this->salesReportRepository->categoryName($filters['category_id']),
            ],
        ];
    }

    /**
     * @return Kpis
     */
    private function kpiData(stdClass $summary): array
    {
        $totalSales = (int) ($summary->total_sales ?? 0);
        $totalRevenue = (float) ($summary->total_revenue ?? 0);

        return [
            'total_sales' => $totalSales,
            'total_revenue' => $this->money($totalRevenue),
            'average_order_value' => $this->money($totalSales === 0 ? 0 : $totalRevenue / $totalSales),
            'total_items_sold' => (int) ($summary->total_items_sold ?? 0),
        ];
    }

    /**
     * @return ProductRow
     */
    private function productData(stdClass $product): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'category' => (string) $product->category_name,
            'sales_count' => (int) $product->sales_count,
            'quantity_sold' => (int) $product->quantity_sold,
            'item_revenue' => $this->money($product->item_revenue),
        ];
    }

    /**
     * @return CategoryRow
     */
    private function categoryData(stdClass $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'sales_count' => (int) $category->sales_count,
            'quantity_sold' => (int) $category->quantity_sold,
            'item_revenue' => $this->money($category->item_revenue),
        ];
    }

    /**
     * @param  list<stdClass>  $dailySales
     * @return array{labels: list<string>, sales: list<int>, revenue: list<float>}
     */
    private function timelineData(
        array $dailySales,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $period,
        bool $includeYear = false,
    ): array {
        $buckets = [];
        $current = $this->periodStart($from, $period);
        $last = $this->periodStart($to, $period);

        while ($current->lessThanOrEqualTo($last)) {
            $key = $current->toDateString();
            $buckets[$key] = [
                'label' => $this->periodLabel($current, $period, $includeYear),
                'sales' => 0,
                'revenue' => 0.0,
            ];
            $current = $this->nextPeriod($current, $period);
        }

        foreach ($dailySales as $dailySale) {
            $date = CarbonImmutable::parse($dailySale->sale_date);
            $key = $this->periodStart($date, $period)->toDateString();

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['sales'] += (int) $dailySale->sales_count;
            $buckets[$key]['revenue'] += (float) $dailySale->revenue;
        }

        return [
            'labels' => array_column($buckets, 'label'),
            'sales' => array_column($buckets, 'sales'),
            'revenue' => array_column($buckets, 'revenue'),
        ];
    }

    private function periodStart(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    private function nextPeriod(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'week' => $date->addWeek(),
            'month' => $date->addMonth(),
            default => $date->addDay(),
        };
    }

    private function periodLabel(CarbonImmutable $date, string $period, bool $includeYear): string
    {
        $dateFormat = $includeYear ? 'M j, Y' : 'M j';

        return match ($period) {
            'week' => $date->format($dateFormat).' – '.$date->endOfWeek()->format($dateFormat),
            'month' => $date->format('M Y'),
            default => $date->format($dateFormat),
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
