<?php

namespace App\Repositories\Reporting;

use App\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class SalesReportRepository
{
    /**
     * Return aggregate KPIs for the selected report scope.
     *
     * @param  array{0: string, 1: string}  $dateRange
     */
    public function summary(array $dateRange, ?int $productId, ?int $categoryId): stdClass
    {
        if ($productId !== null || $categoryId !== null) {
            return $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->join('orders', 'orders.id', '=', 'sales.order_id')
                ->selectRaw('COUNT(DISTINCT sales.id) as total_sales')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as total_revenue')
                ->selectRaw(
                    'COUNT(DISTINCT CASE WHEN orders.status = ? THEN sales.order_id END) as completed_orders',
                    [OrderStatus::Completed->value],
                )
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as total_items_sold')
                ->first();
        }

        $summary = DB::table('sales')
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
        $summary->total_items_sold = $this->itemReportQuery($dateRange)->sum('order_items.quantity');

        return $summary;
    }

    /**
     * Return daily sale aggregates for timeline bucketing.
     *
     * @param  array{0: string, 1: string}  $dateRange
     * @return Collection<int, stdClass>
     */
    public function dailySales(array $dateRange, ?int $productId, ?int $categoryId): Collection
    {
        if ($productId !== null || $categoryId !== null) {
            return $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->select('sales.sale_date')
                ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as revenue')
                ->groupBy('sales.sale_date')
                ->orderBy('sales.sale_date')
                ->get();
        }

        return DB::table('sales')
            ->where('sale_date', '>=', $dateRange[0])
            ->where('sale_date', '<', $dateRange[1])
            ->select('sale_date')
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as revenue')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();
    }

    /**
     * Return the leading product aggregates for the chart.
     *
     * @param  array{0: string, 1: string}  $dateRange
     * @return Collection<int, stdClass>
     */
    public function topProducts(array $dateRange, ?int $categoryId): Collection
    {
        return $this->productReportQuery($dateRange, null, $categoryId)
            ->orderByDesc('quantity_sold')
            ->orderByDesc('item_revenue')
            ->orderBy('products.id')
            ->limit(10)
            ->get();
    }

    /**
     * Return one selected product aggregate when it is outside the chart leaders.
     *
     * @param  array{0: string, 1: string}  $dateRange
     */
    public function selectedProduct(array $dateRange, int $productId, ?int $categoryId): ?stdClass
    {
        return $this->productReportQuery($dateRange, $productId, $categoryId)->first();
    }

    /**
     * Paginate product aggregates for the report table.
     *
     * @param  array{0: string, 1: string}  $dateRange
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function products(
        array $dateRange,
        ?int $productId,
        ?int $categoryId,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->productReportQuery($dateRange, $productId, $categoryId)
            ->orderByDesc('item_revenue')
            ->orderByDesc('quantity_sold')
            ->orderBy('products.id')
            ->paginate($perPage);
    }

    /**
     * Return category aggregates.
     *
     * @param  array{0: string, 1: string}  $dateRange
     * @return Collection<int, stdClass>
     */
    public function categories(
        array $dateRange,
        ?int $productId,
        ?int $categoryId = null,
    ): Collection {
        return $this->categoryReportQuery($dateRange, $productId, $categoryId)
            ->orderByDesc('item_revenue')
            ->orderBy('categories.id')
            ->get();
    }

    public function productName(int $productId): ?string
    {
        return DB::table('products')->where('id', $productId)->value('name');
    }

    public function categoryName(int $categoryId): ?string
    {
        return DB::table('categories')->where('id', $categoryId)->value('name');
    }

    /**
     * @param  array{0: string, 1: string}  $dateRange
     */
    private function productReportQuery(
        array $dateRange,
        ?int $productId = null,
        ?int $categoryId = null,
    ): Builder {
        return $this->itemReportQuery($dateRange, $productId, $categoryId)
            ->select(['products.id', 'products.name', 'categories.name as category_name'])
            ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity_sold')
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as item_revenue')
            ->groupBy('products.id', 'products.name', 'categories.name');
    }

    /**
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
}
