<?php

namespace App\Repositories\Order;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdministratorOrderRepository
{
    /**
     * Return the filtered order directory and counts for each operational view.
     *
     * @param  array<string, mixed>  $filters
     * @return array{orders: LengthAwarePaginator<int, Order>, counts: Collection<string, int>}
     */
    public function directory(array $filters, string $statusFilter): array
    {
        $filteredOrders = $this->applyIndexFilters(Order::query(), $filters);
        $counts = (clone $filteredOrders)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $orders = $this->applyStatusFilter($filteredOrders, $statusFilter)
            ->select([
                'id', 'user_id', 'recipient_name', 'fulfillment_method',
                'total_amount', 'status', 'payment_status', 'payment_method', 'created_at',
            ])
            ->with(['user:id,name,email', 'shipment:id,order_id,status', 'cancellationRequest.refund'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($filters);

        return ['orders' => $orders, 'counts' => $counts];
    }

    public function loadDetails(Order $order): Order
    {
        return $order->load([
            'cancellationRequest.refund',
            'shipment',
            'user:id,name,email',
            'items' => fn ($query) => $query
                ->select(['id', 'order_id', 'product_id', 'quantity', 'price_at_time', 'product_name_at_time'])
                ->orderBy('id'),
            'items.product:id,name,brand,image_path',
        ]);
    }

    /**
     * @return array{has_sale: bool, sales_returns: numeric-string, cost_recovered: numeric-string}
     */
    public function profitabilityInputs(Order $order): array
    {
        $orderHasSale = DB::table('sales')->where('order_id', $order->id)->exists();
        $salesReturns = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.sales_return_amount');
        $costRecovered = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.cost_recovered_amount');

        return ['has_sale' => $orderHasSale, 'sales_returns' => $salesReturns, 'cost_recovered' => $costRecovered];
    }

    /**
     * @return EloquentCollection<int, User>|array{}
     */
    public function handlerOptions(Order $order): EloquentCollection|array
    {
        return $order->status === OrderStatus::Completed || $order->status === OrderStatus::Cancelled
            ? []
            : User::query()->where('role', UserRole::Administrator->value)
                ->where('is_active', true)
                ->whereIn('administration_role_id', DB::table('administration_role_permissions as grants')
                    ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                    ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                    ->where('permissions.code', 'orders.process')->where('roles.code', '!=', 'it')
                    ->select('grants.administration_role_id'))
                ->orderBy('name')->get(['id', 'name']);
    }

    public function assignedHandlerName(Order $order): ?string
    {
        return $order->assigned_handler_id === null ? null : User::query()->whereKey($order->assigned_handler_id)->value('name');
    }

    /**
     * @param  Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function applyIndexFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $referenceId = $this->referenceIdFromSearch($search);

                $query->where(function (Builder $query) use ($referenceId, $search): void {
                    $query
                        ->where('recipient_name', 'like', "%{$search}%")
                        ->orWhereHas(
                            'user',
                            fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"),
                        );

                    if ($referenceId !== null) {
                        $query->orWhereKey($referenceId);
                    }
                });
            })
            ->when(
                $filters['payment_status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('payment_status', $status),
            )
            ->when(
                $filters['payment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('payment_method', $method),
            )
            ->when(
                $filters['fulfillment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('fulfillment_method', $method),
            );
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'completed' => $query->where('status', OrderStatus::Completed->value),
            'cancelled' => $query->where('status', OrderStatus::Cancelled->value),
            default => $query->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Processing->value,
            ]),
        };
    }

    private function referenceIdFromSearch(string $search): ?int
    {
        if (preg_match('/^BF-(\d+)$/i', trim($search), $matches) !== 1) {
            return null;
        }

        $referenceId = (int) $matches[1];

        return $referenceId > 0 ? $referenceId : null;
    }
}
