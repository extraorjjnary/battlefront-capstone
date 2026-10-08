<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('customers receive only their authoritative shopping and order summary', function () {
    $this->travelTo('2026-09-20 12:00:00');
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->create(['quantity' => 2]);
    CartItem::factory()->for($cart)->create(['quantity' => 3]);
    CartItem::factory()
        ->for(Cart::factory()->for($otherCustomer))
        ->create(['quantity' => 9]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'created_at' => now()->subDays(2),
    ]);
    $latestOrder = Order::factory()->for($customer)->delivery()->create([
        'fulfillment_method' => FulfillmentMethod::Delivery->value,
        'status' => OrderStatus::Processing->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '2450.75',
        'created_at' => now()->subHour(),
    ]);
    OrderItem::factory()
        ->count(2)
        ->for($latestOrder)
        ->sequence(['quantity' => 2], ['quantity' => 3])
        ->create();
    Order::factory()->for($otherCustomer)->create([
        'created_at' => now(),
    ]);

    $response = $this
        ->actingAs($customer)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->where('auth.can.accessAdministration', false)
        ->where('dashboard.summary.cart_items', 2)
        ->where('dashboard.summary.cart_units', 5)
        ->where('dashboard.summary.active_orders', 1)
        ->where('dashboard.summary.total_orders', 2)
        ->where('dashboard.latest_order.reference', $latestOrder->reference)
        ->where('dashboard.latest_order.status.value', OrderStatus::Processing->value)
        ->where('dashboard.latest_order.status.label', 'Processing')
        ->where('dashboard.latest_order.item_count', 2)
        ->where('dashboard.latest_order.total_quantity', 5)
        ->where('dashboard.latest_order.total', '2450.75')
        ->missing('dashboard.kpis')
        ->missing('dashboard.needs_attention')
        ->missing('dashboard.recent_orders'));
});

test('administrators receive authoritative operational dashboard data', function () {
    $this->travelTo('2026-09-20 12:00:00');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $pendingOrder = Order::factory()->for($customer)->paidWithGCash()->create([
        'status' => OrderStatus::Pending->value,
        'payment_status' => PaymentStatus::Pending->value,
        'total_amount' => '1250.00',
        'created_at' => now()->subHour(),
    ]);
    OrderItem::factory()->count(2)->for($pendingOrder)->create(['quantity' => 2]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Processing->value,
        'payment_status' => PaymentStatus::Verified->value,
        'created_at' => now()->subHours(2),
    ]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Cancelled->value,
        'payment_status' => PaymentStatus::Pending->value,
        'created_at' => now()->subHours(3),
    ]);
    $firstCompletedOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '1500.25',
        'created_at' => now()->subDays(2),
    ]);
    $secondCompletedOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '2000.25',
        'created_at' => now()->subDay(),
    ]);
    Sale::factory()->for($firstCompletedOrder)->create([
        'amount' => '1500.25',
        'sale_date' => now()->subDays(2)->toDateString(),
    ]);
    Sale::factory()->for($secondCompletedOrder)->create([
        'amount' => '2000.25',
        'sale_date' => now()->subDay()->toDateString(),
    ]);
    $lowStockProduct = Product::factory()->create([
        'name' => 'Low Stock Keyboard',
        'brand' => 'Battlefront Test',
    ]);
    Inventory::factory()->for($lowStockProduct)->create([
        'quantity' => 2,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for(Product::factory())->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $outOfStockProduct = Product::factory()->create(['name' => 'Out of Stock Mouse']);
    Inventory::factory()->for($outOfStockProduct)->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->where('auth.can.accessAdministration', true)
        ->where('dashboard.kpis.recorded_revenue', '3500.50')
        ->where('dashboard.kpis.recorded_sales', 2)
        ->where('dashboard.kpis.pending_orders', 1)
        ->where('dashboard.kpis.processing_orders', 1)
        ->where('dashboard.kpis.low_stock_products', 1)
        ->where('dashboard.kpis.out_of_stock_products', 1)
        ->where('dashboard.needs_attention.pending_payment_reviews', 1)
        ->has('dashboard.needs_attention.payment_orders', 1)
        ->where('dashboard.needs_attention.payment_orders.0.reference', $pendingOrder->reference)
        ->where('dashboard.needs_attention.payment_orders.0.total', '1250.00')
        ->has('dashboard.needs_attention.low_stock_products', 1)
        ->where('dashboard.needs_attention.low_stock_products.0.id', $lowStockProduct->id)
        ->where('dashboard.needs_attention.low_stock_products.0.quantity', 2)
        ->has('dashboard.needs_attention.out_of_stock_products', 1)
        ->where('dashboard.needs_attention.out_of_stock_products.0.id', $outOfStockProduct->id)
        ->where('dashboard.needs_attention.out_of_stock_products.0.quantity', 0)
        ->has('dashboard.recent_orders', 5)
        ->where('dashboard.recent_orders.0.reference', $pendingOrder->reference)
        ->where('dashboard.recent_orders.0.item_count', 2)
        ->where('dashboard.recent_orders.0.total_quantity', 4)
        ->missing('dashboard.summary')
        ->missing('dashboard.latest_order'));
});

test('customers receive stable empty dashboard data', function () {
    $customer = User::factory()->customer()->create();

    $response = $this
        ->actingAs($customer)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->where('dashboard.summary', [
            'cart_items' => 0,
            'cart_units' => 0,
            'active_orders' => 0,
            'total_orders' => 0,
        ])
        ->where('dashboard.latest_order', null));
});

test('administrators receive stable empty dashboard data', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->where('dashboard.kpis', [
            'recorded_revenue' => '0.00',
            'recorded_sales' => 0,
            'pending_orders' => 0,
            'processing_orders' => 0,
            'low_stock_products' => 0,
            'out_of_stock_products' => 0,
        ])
        ->where('dashboard.needs_attention.pending_payment_reviews', 0)
        ->has('dashboard.needs_attention.payment_orders', 0)
        ->has('dashboard.needs_attention.low_stock_products', 0)
        ->has('dashboard.needs_attention.out_of_stock_products', 0)
        ->has('dashboard.recent_orders', 0));
});
