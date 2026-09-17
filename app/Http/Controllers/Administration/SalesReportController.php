<?php

namespace App\Http\Controllers\Administration;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class SalesReportController extends Controller
{
    /**
     * Display the administrator sales dashboard and reports.
     */
    public function index(SalesReportRequest $request): Response
    {
        $validated = $request->validated();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])
            : CarbonImmutable::today();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])
            : $to->subDays(29);
        $period = $validated['period'] ?? 'day';
        $productId = isset($validated['product_id']) ? (int) $validated['product_id'] : null;
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $hasCrossFilter = $productId !== null || $categoryId !== null;
        $dateRange = [$from->toDateString(), $to->addDay()->toDateString()];

        if ($hasCrossFilter) {
            $salesSummary = $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->join('orders', 'orders.id', '=', 'sales.order_id')
                ->selectRaw('COUNT(DISTINCT sales.id) as total_sales')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as total_revenue')
                ->selectRaw(
                    'COUNT(DISTINCT CASE WHEN orders.status = ? THEN sales.order_id END) as completed_orders',
                    [OrderStatus::Completed->value],
                )
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as total_items_sold')
                ->first();
            $dailySales = $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->select('sales.sale_date')
                ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as revenue')
                ->groupBy('sales.sale_date')
                ->orderBy('sales.sale_date')
                ->get();
        } else {
            $salesSummary = DB::table('sales')
                ->join('orders', 'orders.id', '=', 'sales.order_id')
                ->where('sales.sale_date', '>=', $dateRange[0])
                ->where('sales.sale_date', '<', $dateRange[1])
                ->selectRaw('COUNT(sales.id) as total_sales')
                ->selectRaw('COALESCE(SUM(sales.amount), 0) as total_revenue')
                ->selectRaw(
                    'COUNT(CASE WHEN orders.status = ? THEN 1 END) as completed_orders',
                    [OrderStatus::Completed->value],
                )
                ->first();
            $salesSummary->total_items_sold = $this->itemReportQuery($dateRange)
                ->sum('order_items.quantity');
            $dailySales = DB::table('sales')
                ->where('sale_date', '>=', $dateRange[0])
                ->where('sale_date', '<', $dateRange[1])
                ->select('sale_date')
                ->selectRaw('COUNT(*) as sales_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as revenue')
                ->groupBy('sale_date')
                ->orderBy('sale_date')
                ->get();
        }

        $topProducts = $this->productReportQuery($dateRange, null, $categoryId)
            ->orderByDesc('quantity_sold')
            ->orderByDesc('item_revenue')
            ->orderBy('products.id')
            ->limit(10)
            ->get();

        if (
            $productId !== null
            && ! $topProducts->contains(fn (stdClass $product): bool => (int) $product->id === $productId)
        ) {
            $selectedProduct = $this->productReportQuery($dateRange, $productId, $categoryId)->first();

            if ($selectedProduct !== null) {
                $topProducts->push($selectedProduct);
            }
        }

        $products = $this->productReportQuery($dateRange, $productId, $categoryId)
            ->orderByDesc('item_revenue')
            ->orderByDesc('quantity_sold')
            ->orderBy('products.id')
            ->paginate(20)
            ->appends([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'period' => $period,
                'product_id' => $productId,
                'category_id' => $categoryId,
            ])
            ->through(fn (stdClass $product): array => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'category' => $product->category_name,
                'sales_count' => (int) $product->sales_count,
                'quantity_sold' => (int) $product->quantity_sold,
                'item_revenue' => $this->money($product->item_revenue),
            ]);

        $categoryChart = $this->categoryReportQuery($dateRange, $productId)
            ->orderByDesc('item_revenue')
            ->orderBy('categories.id')
            ->get();
        $categoryReport = $this->categoryReportQuery($dateRange, $productId, $categoryId)
            ->orderByDesc('item_revenue')
            ->orderBy('categories.id')
            ->get();

        return Inertia::render('Administration/Reports/Sales', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'period' => $period,
                'product_id' => $productId,
                'category_id' => $categoryId,
            ],
            'active_filters' => [
                'product' => $productId === null ? null : [
                    'id' => $productId,
                    'name' => (string) DB::table('products')->where('id', $productId)->value('name'),
                ],
                'category' => $categoryId === null ? null : [
                    'id' => $categoryId,
                    'name' => (string) DB::table('categories')->where('id', $categoryId)->value('name'),
                ],
            ],
            'kpis' => [
                'total_sales' => (int) ($salesSummary->total_sales ?? 0),
                'total_revenue' => $this->money($salesSummary->total_revenue ?? 0),
                'completed_orders' => (int) ($salesSummary->completed_orders ?? 0),
                'total_items_sold' => (int) ($salesSummary->total_items_sold ?? 0),
            ],
            'timeline' => $this->timelineData(array_values($dailySales->all()), $from, $to, $period),
            'top_products' => [
                'ids' => $topProducts
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
                'labels' => $topProducts->pluck('name')->all(),
                'quantities' => $topProducts
                    ->pluck('quantity_sold')
                    ->map(fn (mixed $quantity): int => (int) $quantity)
                    ->all(),
            ],
            'categories' => [
                'ids' => $categoryChart
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
                'labels' => $categoryChart->pluck('name')->all(),
                'revenue' => $categoryChart
                    ->pluck('item_revenue')
                    ->map(fn (mixed $revenue): float => (float) $revenue)
                    ->all(),
                'rows' => $categoryReport->map(fn (stdClass $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'sales_count' => (int) $category->sales_count,
                    'quantity_sold' => (int) $category->quantity_sold,
                    'item_revenue' => $this->money($category->item_revenue),
                ])->all(),
            ],
            'products' => $products,
        ]);
    }

    /**
     * Build the shared product-level sales aggregate.
     *
     * @param  array{0: string, 1: string}  $dateRange
     */
    private function productReportQuery(
        array $dateRange,
        ?int $productId = null,
        ?int $categoryId = null,
    ): Builder {
        return $this->itemReportQuery($dateRange, $productId, $categoryId)
            ->select([
                'products.id',
                'products.name',
                'categories.name as category_name',
            ])
            ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity_sold')
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as item_revenue')
            ->groupBy('products.id', 'products.name', 'categories.name');
    }

    /**
     * Build the shared category-level sales aggregate.
     *
     * @param  array{0: string, 1: string}  $dateRange
     */
    private function categoryReportQuery(
        array $dateRange,
        ?int $productId = null,
        ?int $categoryId = null,
    ): Builder {
        return $this->itemReportQuery($dateRange, $productId, $categoryId)
            ->select(['categories.id', 'categories.name'])
            ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity_sold')
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as item_revenue')
            ->groupBy('categories.id', 'categories.name');
    }

    /**
     * Build the sale-backed item query shared by dimension aggregates.
     *
     * @param  array{0: string, 1: string}  $dateRange
     */
    private function itemReportQuery(
        array $dateRange,
        ?int $productId = null,
        ?int $categoryId = null,
    ): Builder {
        return DB::table('order_items')
            ->join('sales', 'sales.order_id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.sale_date', '>=', $dateRange[0])
            ->where('sales.sale_date', '<', $dateRange[1])
            ->when(
                $productId !== null,
                fn (Builder $query): Builder => $query->where('order_items.product_id', $productId),
            )
            ->when(
                $categoryId !== null,
                fn (Builder $query): Builder => $query->where('products.category_id', $categoryId),
            );
    }

    /**
     * Convert daily database aggregates into complete chart periods.
     *
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
            $buckets[$key] = $this->emptyTimelineBucket($current, $period);
            $current = $this->nextPeriod($current, $period);
        }

        foreach ($dailySales as $dailySale) {
            $date = CarbonImmutable::parse($dailySale->sale_date);
            $key = $this->periodStart($date, $period)->toDateString();

            if (! isset($buckets[$key])) {
                continue;
            }

            $bucket = $buckets[$key];
            $buckets[$key] = [
                'label' => $bucket['label'],
                'sales' => $bucket['sales'] + (int) $dailySale->sales_count,
                'revenue' => $bucket['revenue'] + (float) $dailySale->revenue,
            ];
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

    /**
     * @return array{label: string, sales: int, revenue: float}
     */
    private function emptyTimelineBucket(CarbonImmutable $date, string $period): array
    {
        return [
            'label' => $this->periodLabel($date, $period),
            'sales' => 0,
            'revenue' => 0.0,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
