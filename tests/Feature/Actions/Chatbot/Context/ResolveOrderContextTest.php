<?php

use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Models\Order;
use App\Models\User;

test('returns only minimal customer-facing status fields for an owned order reference', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->delivery()->paidWithGCash()->for($customer)->create([
        'status' => OrderStatus::Processing,
        'payment_rejection_reason' => PaymentRejectionReason::Other,
        'payment_rejection_note' => 'Private administrator feedback.',
        'created_at' => '2026-09-20 10:00:00',
    ]);

    $context = (new ResolveOrderContext)->execute($customer, "Status of {$order->reference}?");

    expect($context)->toBe([
        'orders' => [[
            'reference' => sprintf('BF-%06d', $order->id),
            'created_at' => '2026-09-20T10:00:00+00:00',
            'status' => [
                'value' => 'processing',
                'label' => 'Preparing for delivery',
            ],
            'fulfillment' => [
                'value' => 'delivery',
                'label' => 'Delivery',
            ],
        ]],
    ]);
});

test('cannot retrieve another customers order by reference', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->for($otherCustomer)->create();

    $context = (new ResolveOrderContext)->execute($customer, "Track {$foreignOrder->reference}");

    expect($context)->toBe(['orders' => []]);
});

test('returns no protected order data for guests and administrators', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create();
    $administrator = User::factory()->administrator()->create();
    $resolver = new ResolveOrderContext;

    $guestContext = $resolver->execute(null, 'Show my order status');
    $administratorContext = $resolver->execute($administrator, 'Show my order status');

    expect($guestContext)->toBe(['orders' => []])
        ->and($administratorContext)->toBe(['orders' => []]);
});

test('uses the persisted customer role when a supplied user model is stale', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create();
    User::query()->whereKey($customer->id)->update([
        'role' => 'administrator',
    ]);

    $context = (new ResolveOrderContext)->execute($customer, 'Show my order status');

    expect($context)->toBe(['orders' => []]);
});

test('returns the five newest owned orders when no reference is supplied', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $orders = collect();

    for ($day = 1; $day <= 6; $day++) {
        $orders->push(Order::factory()->for($customer)->create([
            'created_at' => "2026-09-0{$day} 08:00:00",
        ]));
    }

    Order::factory()->for($otherCustomer)->create(['created_at' => '2026-09-30 08:00:00']);

    $context = (new ResolveOrderContext)->execute($customer, 'What is my order status?');

    expect(array_column($context['orders'], 'reference'))->toBe(
        $orders
            ->reverse()
            ->take(5)
            ->map(fn (Order $order): string => sprintf('BF-%06d', $order->id))
            ->values()
            ->all(),
    );
});

test('an unknown reference returns an explicit empty order context', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create([
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'payment_method' => PaymentMethod::Cash,
    ]);

    $context = (new ResolveOrderContext)->execute($customer, 'Track BF-999999');

    expect($context)->toBe(['orders' => []]);
});
