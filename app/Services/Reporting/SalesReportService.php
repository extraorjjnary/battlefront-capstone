<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\SalesReportRepository;
use Carbon\CarbonImmutable;
use stdClass;

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
        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'])
            : CarbonImmutable::today();
        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'])
            : $to->subDays(29);
        $period = $filters['period'] ?? 'day';
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $dateRange = [$from->toDateString(), $to->addDay()->toDateString()];
        $salesSummary = $this->salesReportRepository->summary($dateRange, $productId, $categoryId);
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

        $normalizedFilters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => $period,
            'product_id' => $productId,
            'category_id' => $categoryId,
        ];
        $products = $this->salesReportRepository
            ->products($dateRange, $productId, $categoryId)
            ->appends($normalizedFilters)
            ->through(fn (stdClass $product): array => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'category' => $product->category_name,
                'sales_count' => (int) $product->sales_count,
                'quantity_sold' => (int) $product->quantity_sold,
                'item_revenue' => $this->money($product->item_revenue),
            ]);
        $categoryChart = $this->salesReportRepository->categories($dateRange, $productId);
        $categoryReport = $this->salesReportRepository->categories($dateRange, $productId, $categoryId);
        $totalSales = (int) ($salesSummary->total_sales ?? 0);
        $totalRevenue = (float) ($salesSummary->total_revenue ?? 0);

        return [
            'filters' => $normalizedFilters,
            'active_filters' => [
                'product' => $productId === null ? null : [
                    'id' => $productId,
                    'name' => (string) $this->salesReportRepository->productName($productId),
                ],
                'category' => $categoryId === null ? null : [
                    'id' => $categoryId,
                    'name' => (string) $this->salesReportRepository->categoryName($categoryId),
                ],
            ],
            'kpis' => [
                'total_sales' => $totalSales,
                'total_revenue' => $this->money($totalRevenue),
                'average_order_value' => $this->money(
                    $totalSales === 0 ? 0 : $totalRevenue / $totalSales,
                ),
                'total_items_sold' => (int) ($salesSummary->total_items_sold ?? 0),
            ],
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
                'rows' => $categoryReport->map(fn (stdClass $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'sales_count' => (int) $category->sales_count,
                    'quantity_sold' => (int) $category->quantity_sold,
                    'item_revenue' => $this->money($category->item_revenue),
                ])->all(),
            ],
            'products' => $products,
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
    ): array {
        $buckets = [];
        $current = $this->periodStart($from, $period);
        $last = $this->periodStart($to, $period);

        while ($current->lessThanOrEqualTo($last)) {
            $key = $current->toDateString();
            $buckets[$key] = [
                'label' => $this->periodLabel($current, $period),
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

    private function periodLabel(CarbonImmutable $date, string $period): string
    {
        return match ($period) {
            'week' => $date->format('M j').' – '.$date->endOfWeek()->format('M j'),
            'month' => $date->format('M Y'),
            default => $date->format('M j'),
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
