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
        $order->sale()->create([
            'amount' => $order->total_amount,
            'sale_date' => today(),
        ]);
    }
}
