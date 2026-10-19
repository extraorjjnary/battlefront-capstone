<?php

namespace App\Repositories\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
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
                ->selectRaw('COUNT(DISTINCT sales.id) as total_sales')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as total_revenue')
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as total_items_sold')
                ->first();
        }

        $summary = $this->recordedSalesSummary($dateRange);
        $summary->total_items_sold = $this->itemReportQuery($dateRange)->sum('order_items.quantity');

        return $summary;
    }

    /**
     * @param  array{0: string, 1: string}  $dateRange
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function transactions(array $dateRange, ?int $productId, ?int $categoryId): LengthAwarePaginator
    {
        $query = $productId === null && $categoryId === null
            ? $this->recordedSaleQuery($dateRange)->addSelect('sales.order_id')
            : $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->select('sales.id', 'sales.order_id', 'sales.sale_date')
                ->selectRaw('SUM(order_items.quantity * order_items.price_at_time) as product_revenue')
                ->groupBy('sales.id', 'sales.order_id', 'sales.sale_date');

        return $query->orderByDesc('sales.sale_date')->orderByDesc('sales.id')
            ->paginate(10, ['*'], 'sale_page')->withQueryString();
    }

    /**
     * Summarize product revenue without rewriting legacy sale amounts.
     *
     * @param  array{0: string, 1: string}|null  $dateRange
     */
    public function recordedSalesSummary(?array $dateRange = null): stdClass
    {
        return DB::query()->fromSub($this->recordedSaleQuery($dateRange), 'recorded_sales')
            ->selectRaw('COUNT(*) as total_sales')
            ->selectRaw('COALESCE(SUM(product_revenue), 0) as total_revenue')
            ->first();
    }

    public function attributedSales(?string $from = null, ?string $to = null): Builder
    {
        return $this->recordedSaleQuery(null)
            ->addSelect('sales.order_id', 'sales.handler_id')
            ->when($from, fn (Builder $query): Builder => $query->whereDate('sales.sale_date', '>=', $from))
            ->when($to, fn (Builder $query): Builder => $query->whereDate('sales.sale_date', '<=', $to));
    }

    /**
     * Prefer the immutable subtotal, then item snapshots, then the saved net payable
     * amount for legacy orders without items. Never subtract fees from sales.amount:
     * historical amounts may include fees while newly recorded amounts exclude them.
     *
     * @param  array{0: string, 1: string}|null  $dateRange
     */
    private function recordedSaleQuery(?array $dateRange): Builder
    {
        $query = DB::table('sales')
            ->join('orders', 'orders.id', '=', 'sales.order_id')
            ->select(['sales.id', 'sales.sale_date'])
            ->selectRaw('COALESCE(orders.product_subtotal,
                (SELECT SUM(items.quantity * items.price_at_time) FROM order_items items WHERE items.order_id = orders.id),
                orders.total_amount - orders.delivery_fee) as product_revenue');

        if ($dateRange !== null) {
            $query->where('sales.sale_date', '>=', $dateRange[0])
                ->where('sales.sale_date', '<', $dateRange[1]);
        }

        return $query;
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

        return DB::query()->fromSub($this->recordedSaleQuery($dateRange), 'recorded_sales')
            ->select('sale_date')
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(product_revenue), 0) as revenue')
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
        return $this->orderedProducts($dateRange, $productId, $categoryId)->paginate($perPage);
    }

    /**
     * Iterate every matching product aggregate without loading individual sales.
     *
     * @param  array{0: string, 1: string}  $dateRange
     * @return LazyCollection<int, stdClass>
     */
    public function exportProducts(array $dateRange, ?int $productId, ?int $categoryId): LazyCollection
    {
        return $this->orderedProducts($dateRange, $productId, $categoryId)->lazy(200);
    }

    /**
     * @param  array{0: string, 1: string}  $dateRange
     */
    private function orderedProducts(array $dateRange, ?int $productId, ?int $categoryId): Builder
    {
        return $this->productReportQuery($dateRange, $productId, $categoryId)
            ->orderByDesc('item_revenue')
            ->orderByDesc('quantity_sold')
            ->orderBy('products.id');
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
