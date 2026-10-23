<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class CustomerReturnEligibility
{
    /** @return array<int, array{remaining_returnable: int, can_request: bool}> */
    public function forOrder(Order $order): array
    {
        $eligible = $this->orderEligible($order);
        $ids = $order->items->modelKeys();
        $physical = DB::table('customer_return_items as items')
            ->join('customer_returns as returns', 'returns.id', '=', 'items.customer_return_id')
            ->whereIn('items.order_item_id', $ids)->whereIn('returns.status', ['pending', 'accepted', 'rejected_pending_handover'])
            ->select('items.order_item_id')->selectRaw('SUM(items.quantity) as quantity')->groupBy('items.order_item_id')->pluck('quantity', 'order_item_id');
        $requests = DB::table('customer_return_requests as requests')
            ->leftJoin('customer_returns as returns', 'returns.id', '=', 'requests.customer_return_id')
            ->whereIn('requests.order_item_id', $ids)->whereNull('requests.withdrawn_at')
            ->select('requests.order_item_id')
            ->selectRaw("SUM(CASE WHEN requests.customer_return_id IS NULL THEN requests.quantity ELSE 0 END) as reserved, MAX(CASE WHEN requests.customer_return_id IS NULL OR returns.status = 'pending' THEN 1 ELSE 0 END) as open_request")
            ->groupBy('requests.order_item_id')->get()->keyBy('order_item_id');

        return $order->items->mapWithKeys(function (OrderItem $item) use ($eligible, $physical, $requests): array {
            $request = $requests->get($item->id);
            $remaining = $eligible ? max(0, $item->quantity - (int) $physical->get($item->id, 0) - (int) ($request->reserved ?? 0)) : 0;

            return [$item->id => ['remaining_returnable' => $remaining, 'can_request' => $remaining > 0 && ! ($request->open_request ?? false)]];
        })->all();
    }

    public function orderEligible(Order $order): bool
    {
        return $order->status === OrderStatus::Completed && $order->sale()->exists();
    }

    public function remaining(OrderItem $item, ?int $convertingRequest = null): int
    {
        $physical = DB::table('customer_return_items as items')
            ->join('customer_returns as returns', 'returns.id', '=', 'items.customer_return_id')
            ->where('items.order_item_id', $item->id)
            ->whereIn('returns.status', ['pending', 'accepted', 'rejected_pending_handover'])
            ->sum('items.quantity');
        $requested = DB::table('customer_return_requests')->where('order_item_id', $item->id)
            ->whereNull('customer_return_id')->whereNull('withdrawn_at')
            ->when($convertingRequest !== null, fn ($query) => $query->where('id', '<>', $convertingRequest))
            ->sum('quantity');

        return max(0, $item->quantity - (int) $physical - (int) $requested);
    }

    public function hasOpenRequest(int $itemId): bool
    {
        return DB::table('customer_return_requests as requests')
            ->leftJoin('customer_returns as returns', 'returns.id', '=', 'requests.customer_return_id')
            ->where('requests.order_item_id', $itemId)->whereNull('requests.withdrawn_at')
            ->where(fn ($query) => $query->whereNull('requests.customer_return_id')->orWhere('returns.status', 'pending'))
            ->exists();
    }
}
