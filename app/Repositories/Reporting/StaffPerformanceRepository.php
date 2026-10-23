<?php

namespace App\Repositories\Reporting;

use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StaffPerformanceRepository
{
    public function handlers(bool $canViewAll, int $actorId): Builder
    {
        return DB::table('users')
            ->when($canViewAll, fn (Builder $query): Builder => $query->where(function (Builder $eligible): void {
                $eligible->where(function (Builder $current): void {
                    $current->where('users.role', 'administrator')->where('users.is_active', true)
                        ->whereIn('users.administration_role_id', DB::table('administration_role_permissions as grants')
                            ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                            ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                            ->where('permissions.code', 'orders.process')
                            ->whereIn('roles.code', ['staff', 'manager', 'owner'])
                            ->select('grants.administration_role_id'));
                })->orWhereExists(fn (Builder $history): Builder => $history->selectRaw('1')
                    ->from('sales')->whereColumn('sales.handler_id', 'users.id'));
            }))
            ->when(! $canViewAll, fn (Builder $query): Builder => $query->where('users.id', $actorId));
    }

    /** @return Collection<int, \stdClass> */
    public function sales(Builder $handlers, Builder $recordedSales): Collection
    {
        return $handlers
            ->leftJoinSub(clone $recordedSales, 'recorded_sales', 'users.id', '=', 'recorded_sales.handler_id')
            ->select('users.id', 'users.name')
            ->selectRaw('COUNT(recorded_sales.id) as orders_count, COALESCE(SUM(recorded_sales.product_revenue), 0) as gross_sales')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('gross_sales')
            ->get();
    }

    /** @return Collection<int, mixed> */
    public function refunds(?string $refundStart, ?string $refundEnd): Collection
    {
        return DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
            ->join('sales', 'sales.order_id', '=', 'returns.order_id')
            ->when($refundStart, fn ($query) => $query->where('payments.paid_at', '>=', $refundStart))
            ->when($refundEnd, fn ($query) => $query->where('payments.paid_at', '<', $refundEnd))
            ->whereExists(fn ($reviews) => $reviews->selectRaw('1')
                ->from('customer_refund_payment_reviews as verified_reviews')
                ->whereColumn('verified_reviews.customer_refund_payment_id', 'payments.id')
                ->where('verified_reviews.decision', 'verified')
                ->when($refundEnd, fn ($query) => $query->where('verified_reviews.created_at', '<', $refundEnd)))
            ->select('sales.handler_id')
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('sales.handler_id')
            ->pluck('amount', 'handler_id');
    }

    /** @return LengthAwarePaginator<int, \stdClass>|null */
    public function transactions(Builder $recordedSales, ?int $handlerId): ?LengthAwarePaginator
    {
        return $handlerId === null ? null : (clone $recordedSales)->where('sales.handler_id', $handlerId)
            ->orderByDesc('sales.sale_date')->orderByDesc('sales.id')
            ->paginate(20, ['*'], 'sale_page')->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, \stdClass>|null  $transactions
     * @return Collection<int, Order>
     */
    public function orders(?LengthAwarePaginator $transactions): Collection
    {
        return $transactions === null ? collect() : Order::query()->whereIn('id', $transactions->getCollection()->pluck('order_id'))
            ->get(['id'])->keyBy('id');
    }

    public function unattributedCount(?string $from, ?string $to): int
    {
        return DB::table('sales')->whereNull('handler_id')
            ->when($from, fn ($query) => $query->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('sale_date', '<=', $to))->count();
    }
}
