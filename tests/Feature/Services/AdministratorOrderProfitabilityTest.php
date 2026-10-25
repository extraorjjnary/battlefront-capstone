<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AdministratorOrderProfitability;

test('order profitability rejects nonnumeric refund totals', function (string $field) {
    $order = Order::factory()->make(['status' => OrderStatus::Completed, 'product_subtotal' => '100.00']);
    $order->setRelation('items', collect([
        OrderItem::factory()->make(['quantity' => 1, 'price_at_time' => '100.00', 'acquisition_cost_at_time' => '30.00']),
    ]));

    expect(fn () => app(AdministratorOrderProfitability::class)->calculate($order, [
        'has_sale' => true, 'sales_returns' => '0.00', 'cost_recovered' => '0.00', $field => 'invalid',
    ]))->toThrow(InvalidArgumentException::class, 'Order profitability amounts must be numeric.');
})->with(['sales_returns', 'cost_recovered']);

test('order profitability preserves decimal arithmetic and saved subtotal precedence', function (?string $subtotal, string $expectedProfit) {
    $order = Order::factory()->make(['status' => OrderStatus::Completed, 'product_subtotal' => $subtotal]);
    $order->setRelation('items', collect([
        OrderItem::factory()->make(['quantity' => 3, 'price_at_time' => '19.99', 'acquisition_cost_at_time' => '7.35']),
        OrderItem::factory()->make(['quantity' => 2, 'price_at_time' => '5.05', 'acquisition_cost_at_time' => '1.11']),
    ]));

    $result = app(AdministratorOrderProfitability::class)->calculate($order, [
        'has_sale' => true, 'sales_returns' => '9.99', 'cost_recovered' => '2.22',
    ]);

    expect($result)->toBe([
        'sales_returns' => '9.99',
        'cost_of_goods_sold' => '24.27',
        'gross_profit' => $expectedProfit,
        'available' => true,
    ]);
})->with([
    'saved subtotal' => ['100.01', '67.97'],
    'legacy item fallback' => [null, '38.03'],
    'zero subtotal' => ['0.00', '-32.04'],
]);

test('order profitability preserves unavailable results and partial known costs', function (OrderStatus $status, bool $hasSale, array $costs, string $expectedCost) {
    $order = Order::factory()->make(['status' => $status, 'product_subtotal' => '100.00']);
    $order->setRelation('items', collect($costs)->map(
        fn (?string $cost): OrderItem => OrderItem::factory()->make([
            'quantity' => 2, 'price_at_time' => '50.00', 'acquisition_cost_at_time' => $cost,
        ]),
    ));

    $result = app(AdministratorOrderProfitability::class)->calculate($order, [
        'has_sale' => $hasSale, 'sales_returns' => '0', 'cost_recovered' => '0',
    ]);

    expect($result)->toBe([
        'sales_returns' => '0',
        'cost_of_goods_sold' => $expectedCost,
        'gross_profit' => null,
        'available' => false,
    ]);
})->with([
    'no items' => [OrderStatus::Completed, true, [], '0.00'],
    'missing cost' => [OrderStatus::Completed, true, ['12.34', null], '24.68'],
    'no sale' => [OrderStatus::Completed, false, ['12.34'], '24.68'],
    'pending' => [OrderStatus::Pending, true, ['12.34'], '24.68'],
    'processing' => [OrderStatus::Processing, true, ['12.34'], '24.68'],
    'cancelled' => [OrderStatus::Cancelled, true, ['12.34'], '24.68'],
]);
