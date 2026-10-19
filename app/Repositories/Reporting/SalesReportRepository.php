<?php

namespace App\Repositories\Reporting;

use Carbon\CarbonImmutable;
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

    /** @param array{0: string, 1: string} $dateRange */
    public function profitabilitySummary(array $dateRange, ?int $productId, ?int $categoryId): stdClass
    {
        $items = $this->itemReportQuery($dateRange, $productId, $categoryId)
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.acquisition_cost_at_time), 0) as cost_of_goods_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.acquisition_cost_at_time IS NULL THEN order_items.quantity ELSE 0 END), 0) as missing_cost_units')
            ->selectRaw('COUNT(DISTINCT sales.id) as sales_with_items')
            ->first();

        $returns = $this->returnReportQuery($dateRange, $productId, $categoryId)->first();

        return (object) [
            'cost_of_goods_sold' => $items->cost_of_goods_sold,
            'missing_cost_units' => (int) $items->missing_cost_units,
            'sales_with_items' => (int) $items->sales_with_items,
            'sales_returns' => $returns->sales_returns ?? '0.00',
            'cost_recovered' => $returns->cost_recovered ?? '0.00',
        ];
    }

    public function recordedProfitabilitySummary(): stdClass
    {
        $summary = $this->recordedSalesSummary();
        $profitability = $this->profitabilitySummary(['1900-01-01', '2100-01-01'], null, null);
        $missing = $profitability->missing_cost_units > 0 || $profitability->sales_with_items < (int) $summary->total_sales;
        $netSales = bcsub($this->numericAmount($summary->total_revenue), $this->numericAmount($profitability->sales_returns), 2);

        return (object) [
            'gross_profit' => $missing ? null : bcsub($netSales, bcsub($this->numericAmount($profitability->cost_of_goods_sold), $this->numericAmount($profitability->cost_recovered), 2), 2),
            'cost_data_complete' => ! $missing,
        ];
    }

    /** @return numeric-string */
    private function numericAmount(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('A report amount must be numeric.');
        }

        return (string) $value;
    }

    /**
     * @param  array{0: string, 1: string}  $dateRange
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function transactions(array $dateRange, ?int $productId, ?int $categoryId): LengthAwarePaginator
    {
        $query = $productId === null && $categoryId === null
            ? $this->recordedSaleQuery($dateRange)
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
        $timezone = config('battlefront.timezone');

        return $this->recordedSaleQuery(null)
            ->addSelect('sales.handler_id')
            ->when($from, fn (Builder $query): Builder => $query->whereDate('sales.sale_date', '>=', CarbonImmutable::parse($from, $timezone)->toDateString()))
            ->when($to, fn (Builder $query): Builder => $query->whereDate('sales.sale_date', '<=', CarbonImmutable::parse($to, $timezone)->toDateString()));
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
            ->select(['sales.id', 'sales.order_id', 'sales.sale_date'])
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
    public function dailySales(array $dateRange, ?int $productId, ?int $categoryId, bool $includeProfitability = true): Collection
    {
        if ($productId !== null || $categoryId !== null) {
            $query = $this->itemReportQuery($dateRange, $productId, $categoryId)
                ->select('sales.sale_date')
                ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.price_at_time), 0) as revenue')
                ->when($includeProfitability, fn (Builder $query): Builder => $query
                    ->selectRaw('COALESCE(SUM(order_items.quantity * (order_items.price_at_time - order_items.acquisition_cost_at_time)), 0) as gross_profit_before_returns')
                    ->selectRaw('COALESCE(SUM(CASE WHEN order_items.acquisition_cost_at_time IS NULL THEN order_items.quantity ELSE 0 END), 0) as missing_cost_units'))
                ->groupBy('sales.sale_date')
                ->orderBy('sales.sale_date');

            return $query->get();
        }

        $query = DB::query()->fromSub($this->recordedSaleQuery($dateRange), 'recorded_sales')
            ->select('sale_date')
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(product_revenue), 0) as revenue')
            ->groupBy('sale_date')
            ->orderBy('sale_date');

        if ($includeProfitability) {
            $itemCosts = DB::table('order_items')
                ->select('order_id')
                ->selectRaw('SUM(quantity * acquisition_cost_at_time) as cost_of_goods_sold')
                ->selectRaw('SUM(CASE WHEN acquisition_cost_at_time IS NULL THEN quantity ELSE 0 END) as missing_cost_units')
                ->selectRaw('COUNT(*) as item_rows')
                ->groupBy('order_id');
            $query->leftJoinSub($itemCosts, 'item_costs', 'item_costs.order_id', '=', 'recorded_sales.order_id')
                ->addSelect(DB::raw('COALESCE(SUM(recorded_sales.product_revenue - COALESCE(item_costs.cost_of_goods_sold, 0)), 0) as gross_profit_before_returns'))
                ->addSelect(DB::raw('SUM(CASE WHEN item_costs.item_rows IS NULL OR item_costs.missing_cost_units > 0 THEN 1 ELSE 0 END) as incomplete_sales'));
        }

        return $query->get();
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
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.acquisition_cost_at_time), 0) as cost_of_goods_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.acquisition_cost_at_time IS NULL THEN order_items.quantity ELSE 0 END), 0) as missing_cost_units')
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
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.acquisition_cost_at_time), 0) as cost_of_goods_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.acquisition_cost_at_time IS NULL THEN order_items.quantity ELSE 0 END), 0) as missing_cost_units')
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

    /** @param array{0: string, 1: string} $dateRange */
    private function returnReportQuery(array $dateRange, ?int $productId, ?int $categoryId): Builder
    {
        $from = CarbonImmutable::parse($dateRange[0], config('battlefront.timezone'))->startOfDay()->utc()->toDateTimeString();
        $to = CarbonImmutable::parse($dateRange[1], config('battlefront.timezone'))->startOfDay()->utc()->toDateTimeString();

        return DB::table('customer_return_items as return_items')
            ->join('customer_returns as returns', 'returns.id', '=', 'return_items.customer_return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereNotNull('return_items.financial_recognized_at')
            ->where('return_items.financial_recognized_at', '>=', $from)
            ->where('return_items.financial_recognized_at', '<', $to)
            ->when($productId !== null, fn (Builder $query): Builder => $query->where('products.id', $productId))
            ->when($categoryId !== null, fn (Builder $query): Builder => $query->where('products.category_id', $categoryId))
            ->selectRaw('COALESCE(SUM(return_items.sales_return_amount), 0) as sales_returns')
            ->selectRaw('COALESCE(SUM(return_items.cost_recovered_amount), 0) as cost_recovered');
    }
}
