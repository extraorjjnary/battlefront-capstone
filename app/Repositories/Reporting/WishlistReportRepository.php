<?php

namespace App\Repositories\Reporting;

use App\Models\Category;
use App\Models\Product;
use App\Models\WishlistActivity;
use App\Models\WishlistItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

class WishlistReportRepository
{
    /** @return list<array{id:int,name:string}> */
    public function categoryOptions(): array
    {
        return array_values(Category::query()->orderBy('name')->get(['id', 'name'])
            ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
            ->all());
    }

    /** @param array<string, mixed> $filters
     * @return array{entries: int, customers: int, products: int}
     */
    public function overview(array $filters = []): array
    {
        $query = WishlistItem::query()->toBase()
            ->join('products', 'products.id', '=', 'wishlist_items.product_id')
            ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id');
        $this->applyProductFilters($query, $filters);
        $counts = $query
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COUNT(DISTINCT wishlist_items.user_id) as customers')
            ->selectRaw('COUNT(DISTINCT wishlist_items.product_id) as products')
            ->first();

        return [
            'entries' => (int) $counts->entries,
            'customers' => (int) $counts->customers,
            'products' => (int) $counts->products,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(array $filters): LengthAwarePaginator
    {
        return $this->productsQuery($filters)->paginate(20)->appends($filters);
    }

    /** @param array<string, mixed> $filters
     * @return list<Product>
     */
    public function insightProducts(array $filters, string $stock, string $sort, int $limit = 10, bool $increasing = false): array
    {
        $filters['stock'] = $stock;
        $filters['sort'] = $sort;
        $query = $this->productsQuery($filters)
            ->whereRaw('COALESCE(saved_customers.customers_count, 0) >= ?', [$filters['minimum_saves']]);

        if ($increasing) {
            $query
                ->whereRaw('(COALESCE(current_activity.additions, 0) - COALESCE(current_activity.removals, 0)) >= ?', [$filters['minimum_net_additions']])
                ->whereRaw('(COALESCE(previous_activity.additions, 0) - COALESCE(previous_activity.removals, 0)) > 0')
                ->whereRaw('((COALESCE(current_activity.additions, 0) - COALESCE(current_activity.removals, 0)) - (COALESCE(previous_activity.additions, 0) - COALESCE(previous_activity.removals, 0))) * 100.0 / (COALESCE(previous_activity.additions, 0) - COALESCE(previous_activity.removals, 0)) >= ?', [$filters['minimum_increase_percent']]);
        }

        return array_values($query->limit($limit)->get()->all());
    }

    /** @param array<string, mixed> $filters
     * @return LazyCollection<int, Product>
     */
    public function exportProducts(array $filters): LazyCollection
    {
        return $this->productsQuery($filters)->lazy(200);
    }

    /** @return array{additions: int, removals: int} */
    public function activityTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = WishlistActivity::query()->toBase()
            ->where('occurred_at', '>=', $from->utc()->toDateTimeString())
            ->where('occurred_at', '<', $to->addDay()->utc()->toDateTimeString())
            ->selectRaw("SUM(CASE WHEN event_type = 'added' THEN 1 ELSE 0 END) as additions")
            ->selectRaw("SUM(CASE WHEN event_type = 'removed' THEN 1 ELSE 0 END) as removals")
            ->first();

        return [
            'additions' => (int) ($totals->additions ?? 0),
            'removals' => (int) ($totals->removals ?? 0),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return list<array{date: string, additions: int, removals: int}>
     */
    public function dailyActivity(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $localDate = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', datetime(occurred_at, '+8 hours'))"
            : 'DATE(DATE_ADD(occurred_at, INTERVAL 8 HOUR))';

        $query = WishlistActivity::query()->toBase()
            ->where('occurred_at', '>=', $from->utc()->toDateTimeString())
            ->where('occurred_at', '<', $to->addDay()->utc()->toDateTimeString());

        if ($filters !== []) {
            $query->whereExists(function (QueryBuilder $productQuery) use ($filters): void {
                $productQuery->selectRaw('1')->from('products')
                    ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id')
                    ->whereColumn('products.id', 'wishlist_activities.product_id');
                $this->applyProductFilters($productQuery, $filters);
            });
        }

        return array_values($query
            ->selectRaw("{$localDate} as activity_date")
            ->selectRaw("SUM(CASE WHEN event_type = 'added' THEN 1 ELSE 0 END) as additions")
            ->selectRaw("SUM(CASE WHEN event_type = 'removed' THEN 1 ELSE 0 END) as removals")
            ->groupByRaw($localDate)
            ->orderBy('activity_date')
            ->get()
            ->map(static fn (stdClass $row): array => [
                'date' => (string) $row->activity_date,
                'additions' => (int) $row->additions,
                'removals' => (int) $row->removals,
            ])
            ->all());
    }

    public function activityCoverageStart(): ?CarbonImmutable
    {
        $firstActivity = WishlistActivity::query()->min('occurred_at');

        return $firstActivity === null
            ? null
            : CarbonImmutable::parse((string) $firstActivity, 'UTC')->setTimezone(config('battlefront.timezone'))->startOfDay();
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Product>
     */
    private function productsQuery(array $filters): Builder
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], config('battlefront.timezone'));
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], config('battlefront.timezone'));
        $previousDays = $to->diffInDays($from) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($previousDays - 1);
        $currentActivity = $this->productActivity($from, $to, $filters);
        $previousActivity = $this->productActivity($previousFrom, $previousTo, $filters);
        $savedCustomers = WishlistItem::query()->toBase()
            ->select('product_id')
            ->selectRaw('COUNT(DISTINCT user_id) as customers_count')
            ->groupBy('product_id');

        return Product::query()->withTrashed()
            ->leftJoinSub($savedCustomers, 'saved_customers', 'saved_customers.product_id', '=', 'products.id')
            ->leftJoinSub($currentActivity, 'current_activity', 'current_activity.product_id', '=', 'products.id')
            ->leftJoinSub($previousActivity, 'previous_activity', 'previous_activity.product_id', '=', 'products.id')
            ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id')
            ->select('products.*')
            ->selectRaw('COALESCE(saved_customers.customers_count, 0) as wishlist_customers')
            ->selectRaw('COALESCE(current_activity.additions, 0) as period_additions')
            ->selectRaw('COALESCE(current_activity.removals, 0) as period_removals')
            ->selectRaw('COALESCE(previous_activity.additions, 0) as previous_additions')
            ->selectRaw('COALESCE(previous_activity.removals, 0) as previous_removals')
            ->addSelect([
                'wishlist_stock_quantity' => DB::table('inventories as stock')
                    ->select('stock.quantity')->whereColumn('stock.product_id', 'products.id')->limit(1),
                'wishlist_reorder_level' => DB::table('inventories as stock')
                    ->select('stock.reorder_level')->whereColumn('stock.product_id', 'products.id')->limit(1),
            ])
            ->with([
                'category' => static fn ($query) => $query->withTrashed(),
            ])
            ->when($filters['q'] !== '', static fn (Builder $query): Builder => $query->where(
                static fn (Builder $search): Builder => $search
                    ->where('products.name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('products.product_code', 'like', '%'.$filters['q'].'%'),
            ))
            ->when($filters['category_id'] !== null, static fn (Builder $query): Builder => $query->where('products.category_id', $filters['category_id']))
            ->where(static function (Builder $query): void {
                $query->whereExists(static fn (QueryBuilder $saved): QueryBuilder => $saved->selectRaw('1')
                    ->from('wishlist_items')->whereColumn('wishlist_items.product_id', 'products.id'))
                    ->orWhereExists(static fn (QueryBuilder $history): QueryBuilder => $history->selectRaw('1')
                        ->from('wishlist_activities')->whereColumn('wishlist_activities.product_id', 'products.id'));
            })
            ->when($filters['stock'] === 'in_stock', static fn (Builder $query): Builder => $query->where('inventories.quantity', '>', 0)->whereColumn('inventories.quantity', '>', 'inventories.reorder_level'))
            ->when($filters['stock'] === 'low_stock', static fn (Builder $query): Builder => $query->where('inventories.quantity', '>', 0)->whereColumn('inventories.quantity', '<=', 'inventories.reorder_level'))
            ->when($filters['stock'] === 'out_of_stock', static fn (Builder $query): Builder => $query->where('inventories.quantity', 0))
            ->when($filters['stock'] === 'unavailable', static fn (Builder $query): Builder => $query->whereNull('inventories.product_id'))
            ->orderByRaw(match ($filters['sort'].':'.$filters['direction']) {
                'name:asc' => 'products.name ASC',
                'name:desc' => 'products.name DESC',
                'price:asc' => 'COALESCE(products.discount_price, products.price) ASC',
                'price:desc' => 'COALESCE(products.discount_price, products.price) DESC',
                'stock:asc' => 'inventories.quantity ASC',
                'stock:desc' => 'inventories.quantity DESC',
                'interest:asc' => '(COALESCE(current_activity.additions, 0) - COALESCE(current_activity.removals, 0)) ASC',
                'interest:desc' => '(COALESCE(current_activity.additions, 0) - COALESCE(current_activity.removals, 0)) DESC',
                'customers:asc' => 'COALESCE(saved_customers.customers_count, 0) ASC',
                default => 'COALESCE(saved_customers.customers_count, 0) DESC',
            })
            ->orderBy('products.id');
    }

    /** @param array<string, mixed> $filters */
    private function productActivity(CarbonImmutable $from, CarbonImmutable $to, array $filters): QueryBuilder
    {
        $query = WishlistActivity::query()->toBase()
            ->whereNotNull('product_id')
            ->where('occurred_at', '>=', $from->utc()->toDateTimeString())
            ->where('occurred_at', '<', $to->addDay()->utc()->toDateTimeString());

        if ($filters !== []) {
            $query->whereExists(function (QueryBuilder $productQuery) use ($filters): void {
                $productQuery->selectRaw('1')->from('products')
                    ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id')
                    ->whereColumn('products.id', 'wishlist_activities.product_id');
                $this->applyProductFilters($productQuery, $filters);
            });
        }

        return $query
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN event_type = 'added' THEN 1 ELSE 0 END) as additions")
            ->selectRaw("SUM(CASE WHEN event_type = 'removed' THEN 1 ELSE 0 END) as removals")
            ->groupBy('product_id');
    }

    /** @param array<string, mixed> $filters */
    private function applyProductFilters(QueryBuilder $query, array $filters): void
    {
        if (($filters['q'] ?? '') !== '') {
            $query->where(static function (QueryBuilder $search) use ($filters): void {
                $search->where('products.name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('products.product_code', 'like', '%'.$filters['q'].'%');
            });
        }

        if (($filters['category_id'] ?? null) !== null) {
            $query->where('products.category_id', $filters['category_id']);
        }

        match ($filters['stock'] ?? 'all') {
            'in_stock' => $query->where('inventories.quantity', '>', 0)->whereColumn('inventories.quantity', '>', 'inventories.reorder_level'),
            'low_stock' => $query->where('inventories.quantity', '>', 0)->whereColumn('inventories.quantity', '<=', 'inventories.reorder_level'),
            'out_of_stock' => $query->where('inventories.quantity', 0),
            'unavailable' => $query->whereNull('inventories.product_id'),
            default => null,
        };
    }
}
