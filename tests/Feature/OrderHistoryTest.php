<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from order history', function () {
    $this->get(route('orders.index'))
        ->assertRedirectToRoute('login');
});

test('administrators are forbidden from order history', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('orders.index'))
        ->assertForbidden();
});

test('customers see only their orders with authoritative summaries', function () {
    $customer = User::factory()->customer()->create();
    $olderOrder = Order::factory()->for($customer)->create([
        'id' => 41,
        'created_at' => '2026-09-01 08:00:00',
        'total_amount' => '1500.00',
    ]);
    $newerOrder = Order::factory()->for($customer)->delivery()->paidWithGCash()->create([
        'id' => 42,
        'created_at' => '2026-09-02 09:30:00',
        'total_amount' => '5000.00',
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();
    OrderItem::factory()->for($olderOrder)->for($firstProduct)->create([
        'quantity' => 1,
        'price_at_time' => '1500.00',
    ]);
    OrderItem::factory()->for($newerOrder)->for($firstProduct)->create([
        'quantity' => 2,
        'price_at_time' => '1500.00',
    ]);
    OrderItem::factory()->for($newerOrder)->for($secondProduct)->create([
        'quantity' => 1,
        'price_at_time' => '2000.00',
    ]);
    $otherCustomerOrder = Order::factory()->create();

    $response = $this->actingAs($customer)->get(route('orders.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Index')
        ->where('orders.total', 2)
        ->has('orders.data', 2)
        ->where('orders.data.0.id', $newerOrder->id)
        ->where('orders.data.0.reference', 'BF-000042')
        ->where('orders.data.0.status', [
            'value' => OrderStatus::Processing->value,
            'label' => 'Preparing for delivery',
        ])
        ->where('orders.data.0.fulfillment', [
            'value' => FulfillmentMethod::Delivery->value,
            'label' => 'Delivery',
        ])
        ->where('orders.data.0.payment', [
            'method' => [
                'value' => PaymentMethod::GCash->value,
                'label' => 'GCash',
            ],
            'status' => [
                'value' => PaymentStatus::Verified->value,
                'label' => 'Verified',
            ],
        ])
        ->where('orders.data.0.item_count', 2)
        ->where('orders.data.0.total_quantity', 3)
        ->where('orders.data.0.total', '5000.00')
        ->where('orders.data.1.id', $olderOrder->id));

    expect(collect($response->inertiaProps('orders.data'))->pluck('id'))
        ->not->toContain($otherCustomerOrder->id);
});

test('order history is paginated ten orders at a time', function () {
    $customer = User::factory()->customer()->create();
    $orders = Order::factory()->count(11)->for($customer)->create();

    $response = $this->actingAs($customer)->get(route('orders.index', ['page' => 2]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Index')
        ->where('orders.current_page', 2)
        ->where('orders.last_page', 2)
        ->where('orders.per_page', 10)
        ->where('orders.total', 11)
        ->has('orders.data', 1)
        ->where('orders.data.0.id', $orders->first()->id));
});

test('order history provides an empty result for customers without orders', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Index')
            ->where('orders.total', 0)
            ->has('orders.data', 0));
});
