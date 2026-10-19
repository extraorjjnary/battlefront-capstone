<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\SalesReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * @phpstan-type SalesFilters array{from: string, to: string, period: string, product_id: ?int, category_id: ?int}
 * @phpstan-type ActiveFilters array{product: ?array{id: int, name: string}, category: ?array{id: int, name: string}}
 * @phpstan-type ProductRow array{id: int, name: string, category: string, sales_count: int, quantity_sold: int, item_revenue: string, cost_of_goods_sold?: string, gross_profit_before_returns?: string|null, gross_margin_before_returns?: string|null}
 * @phpstan-type CategoryRow array{id: int, name: string, sales_count: int, quantity_sold: int, item_revenue: string, cost_of_goods_sold?: string, gross_profit_before_returns?: string|null, gross_margin_before_returns?: string|null}
 * @phpstan-type Kpis array{total_sales: int, total_revenue: numeric-string, average_order_value: string, total_items_sold: int, sales_returns?: string, net_sales?: string, cost_of_goods_sold?: string, cost_recovered?: string, gross_profit?: string|null, gross_margin?: string|null, cost_data_complete?: bool}
 * @phpstan-type Timeline array{labels: list<string>, sales: list<int>, revenue: list<float>, gross_profit_before_returns?: list<float|null>}
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
    public function generate(array $filters, bool $includeProfitability = true): array
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
        $dailySales = $this->salesReportRepository->dailySales($dateRange, $productId, $categoryId, $includeProfitability);
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
            ->through(fn (stdClass $product): array => $this->productData($product, $includeProfitability));
        $categoryChart = $this->salesReportRepository->categories($dateRange, $productId);
        $categoryReport = $this->salesReportRepository->categories($dateRange, $productId, $categoryId);

        return [
            'filters' => $normalizedFilters,
            'active_filters' => $this->activeFilters($normalizedFilters),
            'kpis' => $this->kpiData($salesSummary, $includeProfitability
                ? $this->salesReportRepository->profitabilitySummary($dateRange, $productId, $categoryId)
                : null),
            'comparison' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'total_revenue' => (string) $previousSummary->total_revenue,
                'total_sales' => (int) $previousSummary->total_sales,
            ],
            'sale_transactions' => $this->salesReportRepository->transactions($dateRange, $productId, $categoryId),
            'timeline' => $this->timelineData(array_values($dailySales->all()), $from, $to, $period, includeProfitability: $includeProfitability),
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
                'rows' => $categoryReport->map(fn (stdClass $category): array => $this->categoryData($category, $includeProfitability))->all(),
            ],
            'products' => $products,
        ];
    }

    /**
     * Prepare CSV metadata and lazy product rows without querying dashboard charts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: SalesFilters, active_filters: ActiveFilters, kpis: Kpis, products: LazyCollection<int, covariant ProductRow>}
     */
    public function exportProducts(array $filters, bool $includeProfitability = true): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);

        return [
            'filters' => $normalizedFilters,
            'active_filters' => $this->activeFilters($normalizedFilters),
            'kpis' => $this->kpiData(
                $this->salesReportRepository->summary(
                    $this->dateRange($normalizedFilters),
                    $normalizedFilters['product_id'],
                    $normalizedFilters['category_id'],
                ),
                $includeProfitability
                    ? $this->salesReportRepository->profitabilitySummary($this->dateRange($normalizedFilters), $normalizedFilters['product_id'], $normalizedFilters['category_id'])
                    : null,
            ),
            'products' => $this->salesReportRepository->exportProducts(
                $this->dateRange($normalizedFilters),
                $normalizedFilters['product_id'],
                $normalizedFilters['category_id'],
            )->map(fn (stdClass $product): array => $this->productData($product, $includeProfitability)),
        ];
    }

    /**
     * Build the common print/PDF document from the authoritative aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: SalesFilters, active_filters: ActiveFilters, products: LazyCollection<int, covariant ProductRow>, kpis: Kpis, timeline: Timeline, categories: list<CategoryRow>}
     */
    public function document(array $filters, bool $includeProfitability = true): array
    {
        $report = $this->exportProducts($filters, $includeProfitability);
        $scope = $report['filters'];
        $dateRange = $this->dateRange($scope);
        $dailySales = $this->salesReportRepository->dailySales($dateRange, $scope['product_id'], $scope['category_id'], $includeProfitability);

        return [
            ...$report,
            'kpis' => $this->kpiData(
                $this->salesReportRepository->summary($dateRange, $scope['product_id'], $scope['category_id']),
                $includeProfitability
                    ? $this->salesReportRepository->profitabilitySummary($dateRange, $scope['product_id'], $scope['category_id'])
                    : null,
            ),
            'timeline' => $this->timelineData(
                array_values($dailySales->all()),
                CarbonImmutable::parse($scope['from']),
                CarbonImmutable::parse($scope['to']),
                $scope['period'],
                includeYear: true,
                includeProfitability: $includeProfitability,
            ),
            'categories' => array_values($this->salesReportRepository->categories($dateRange, $scope['product_id'], $scope['category_id'])
                ->map(fn (stdClass $category): array => $this->categoryData($category, $includeProfitability))->all()),
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
    private function kpiData(stdClass $summary, ?stdClass $profitability): array
    {
        $totalSales = (int) ($summary->total_sales ?? 0);
        $totalRevenue = $this->numericAmount($summary->total_revenue ?? '0.00');
        $kpis = [
            'total_sales' => $totalSales,
            'total_revenue' => $this->money($totalRevenue),
            'average_order_value' => $this->money($totalSales === 0 ? 0 : (float) $totalRevenue / $totalSales),
            'total_items_sold' => (int) ($summary->total_items_sold ?? 0),
        ];

        if ($profitability === null) {
            return $kpis;
        }

        $salesReturns = $this->numericAmount($profitability->sales_returns);
        $costOfGoodsSold = $this->numericAmount($profitability->cost_of_goods_sold);
        $costRecovered = $this->numericAmount($profitability->cost_recovered);
        $netSales = bcsub($totalRevenue, $salesReturns, 2);
        $missingCosts = $profitability->missing_cost_units > 0
            || $profitability->sales_with_items < $totalSales;
        $grossProfit = $missingCosts ? null : bcsub($netSales, bcsub($costOfGoodsSold, $costRecovered, 2), 2);

        return [
            ...$kpis,
            'sales_returns' => $this->money($salesReturns),
            'net_sales' => $this->money($netSales),
            'cost_of_goods_sold' => $this->money($costOfGoodsSold),
            'cost_recovered' => $this->money($costRecovered),
            'gross_profit' => $grossProfit === null ? null : $this->money($grossProfit),
            'gross_margin' => $grossProfit === null || bccomp($netSales, '0.00', 2) <= 0
                ? null
                : $this->money(bcmul(bcdiv($grossProfit, $netSales, 6), '100', 2)),
            'cost_data_complete' => ! $missingCosts,
        ];
    }

    /**
     * @return ProductRow
     */
    private function productData(stdClass $product, bool $includeProfitability = true): array
    {
        $data = [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'category' => (string) $product->category_name,
            'sales_count' => (int) $product->sales_count,
            'quantity_sold' => (int) $product->quantity_sold,
            'item_revenue' => $this->money($this->numericAmount($product->item_revenue)),
        ];

        return $includeProfitability ? [...$data, ...$this->profitabilityRow($product)] : $data;
    }

    /**
     * @return CategoryRow
     */
    private function categoryData(stdClass $category, bool $includeProfitability = true): array
    {
        $data = [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'sales_count' => (int) $category->sales_count,
            'quantity_sold' => (int) $category->quantity_sold,
            'item_revenue' => $this->money($this->numericAmount($category->item_revenue)),
        ];

        return $includeProfitability ? [...$data, ...$this->profitabilityRow($category)] : $data;
    }

    /** @return array{cost_of_goods_sold: string, gross_profit_before_returns: string|null, gross_margin_before_returns: string|null} */
    private function profitabilityRow(stdClass $row): array
    {
        $revenue = $this->numericAmount($row->item_revenue);
        $cost = $this->numericAmount($row->cost_of_goods_sold);
        $profit = (int) $row->missing_cost_units > 0 ? null : bcsub($revenue, $cost, 2);

        return [
            'cost_of_goods_sold' => $this->money($cost),
            'gross_profit_before_returns' => $profit === null ? null : $this->money($profit),
            'gross_margin_before_returns' => $profit === null || bccomp($revenue, '0.00', 2) <= 0
                ? null
                : $this->money(bcmul(bcdiv($profit, $revenue, 6), '100', 2)),
        ];
    }

    /**
     * @param  list<stdClass>  $dailySales
     * @return array{labels: list<string>, sales: list<int>, revenue: list<float>, gross_profit_before_returns?: list<float|null>}
     */
    private function timelineData(
        array $dailySales,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $period,
        bool $includeYear = false,
        bool $includeProfitability = true,
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
                'gross_profit_before_returns' => 0.0,
                'incomplete_sales' => 0,
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
            if ($includeProfitability) {
                $buckets[$key]['gross_profit_before_returns'] += (float) $dailySale->gross_profit_before_returns;
                $buckets[$key]['incomplete_sales'] += (int) ($dailySale->incomplete_sales ?? ($dailySale->missing_cost_units > 0 ? 1 : 0));
            }
        }

        $timeline = [
            'labels' => array_column($buckets, 'label'),
            'sales' => array_column($buckets, 'sales'),
            'revenue' => array_column($buckets, 'revenue'),
        ];

        return $includeProfitability ? [
            ...$timeline,
            'gross_profit_before_returns' => array_map(
                static fn (array $bucket): ?float => $bucket['incomplete_sales'] > 0
                    ? null
                    : $bucket['gross_profit_before_returns'],
                array_values($buckets),
            ),
        ] : $timeline;
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

    /** @return numeric-string */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** @return numeric-string */
    private function numericAmount(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('A report amount must be numeric.');
        }

        return (string) $value;
    }
}
