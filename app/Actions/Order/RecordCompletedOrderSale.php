<?php

namespace App\Actions\Order;

use App\Models\Order;

class RecordCompletedOrderSale
{
    /**
     * Persist the sale generated when an order is completed.
     */
    public function execute(Order $order): void
    {
        $subtotal = $order->product_subtotal;

        if ($subtotal === null) {
            $itemSubtotal = $order->items()
                ->selectRaw('SUM(quantity * price_at_time) as product_subtotal')
                ->value('product_subtotal');
            $subtotal = $itemSubtotal === null
                ? bcsub($order->total_amount, $order->delivery_fee, 2)
                : number_format((float) $itemSubtotal, 2, '.', '');
        }

        $order->sale()->create([
            'amount' => $subtotal,
            'sale_date' => today(),
            'handler_id' => $order->assigned_handler_id,
        ]);
    }
}
