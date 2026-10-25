<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;

class AdministratorOrderProfitability
{
    /**
     * @param  array{has_sale: bool, sales_returns: numeric-string, cost_recovered: numeric-string}  $inputs
     * @return array{sales_returns: string, cost_of_goods_sold: string, gross_profit: string|null, available: bool}
     */
    public function calculate(Order $order, array $inputs): array
    {
        $orderHasSale = $inputs['has_sale'];
        $salesReturns = $inputs['sales_returns'];
        $costRecovered = $inputs['cost_recovered'];
        $costOfGoodsSold = '0.00';
        $hasMissingCost = $order->items->isEmpty();
        foreach ($order->items as $item) {
            if ($item->acquisition_cost_at_time === null) {
                $hasMissingCost = true;

                continue;
            }
            $costOfGoodsSold = bcadd($costOfGoodsSold, bcmul($item->acquisition_cost_at_time, (string) $item->quantity, 2), 2);
        }
        $productRevenue = $order->product_subtotal ?? $order->items->reduce(
            fn (string $total, OrderItem $item): string => bcadd(
                $total,
                bcmul($item->price_at_time, (string) $item->quantity, 2),
                2,
            ),
            '0.00',
        );
        $grossProfit = ! $hasMissingCost && $orderHasSale && $order->status === OrderStatus::Completed
            ? bcsub(bcsub($productRevenue, $salesReturns, 2), bcsub($costOfGoodsSold, $costRecovered, 2), 2)
            : null;

        return [
            'sales_returns' => $salesReturns,
            'cost_of_goods_sold' => $costOfGoodsSold,
            'gross_profit' => $grossProfit,
            'available' => $grossProfit !== null,
        ];
    }
}
