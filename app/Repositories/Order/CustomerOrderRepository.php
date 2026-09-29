<?php

namespace App\Repositories\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerOrderRepository
{
    /** @return LengthAwarePaginator<int, Order> */
    public function paginate(User $customer): LengthAwarePaginator
    {
        return $customer->orders()
            ->select([
                'id',
                'user_id',
                'fulfillment_method',
                'total_amount',
                'status',
                'payment_status',
                'payment_method',
                'created_at',
            ])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();
    }

    public function find(User $customer, int $order): Order
    {
        return $customer->orders()
            ->select([
                'id',
                'user_id',
                'recipient_name',
                'contact_number',
                'fulfillment_method',
                'delivery_address',
                'total_amount',
                'status',
                'payment_status',
                'payment_method',
                'payment_proof_path',
                'payment_rejection_reason',
                'payment_rejection_note',
                'created_at',
            ])
            ->with([
                'items' => fn ($query) => $query
                    ->select(['id', 'order_id', 'product_id', 'quantity', 'price_at_time'])
                    ->orderBy('id'),
                'items.product:id,name,brand,image_path',
            ])
            ->whereKey($order)
            ->firstOrFail();
    }
}
