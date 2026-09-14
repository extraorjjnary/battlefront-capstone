<?php

use App\Actions\Cart\ManageCart;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from order confirmation', function () {
    $order = Order::factory()->create();

    $this->get(route('orders.show', $order))
        ->assertRedirectToRoute('login');
});

test('administrators are forbidden from order confirmation', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create();

    $this->actingAs($administrator)
        ->get(route('orders.show', $order))
        ->assertForbidden();
});

test('customers see authoritative persisted order confirmation details', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'name' => 'Battlefront Graphics Card',
        'brand' => 'NVIDIA',
        'price' => '99999.00',
    ]);
    $order = Order::factory()
        ->for($customer)
        ->delivery()
        ->paidWithGCash()
        ->create([
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'delivery_address' => 'Sagay City, Negros Occidental',
            'total_amount' => '2500.00',
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'payment_proof_path' => 'payment-proofs/private-proof.png',
        ]);
    $item = OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 2,
        'price_at_time' => '1250.00',
    ]);

    $response = $this->actingAs($customer)->get(route('orders.show', $order));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Show')
        ->where('order.id', $order->id)
        ->where('order.reference', '#'.$order->id)
        ->where('order.status', ['value' => 'pending', 'label' => 'Pending'])
        ->where('order.recipient', [
            'name' => 'Alex Customer',
            'contact_number' => '09171234567',
        ])
        ->where('order.fulfillment', [
            'value' => FulfillmentMethod::Delivery->value,
            'label' => 'Delivery',
            'delivery_address' => 'Sagay City, Negros Occidental',
        ])
        ->where('order.payment', [
            'method' => [
                'value' => PaymentMethod::GCash->value,
                'label' => 'GCash',
            ],
            'status' => [
                'value' => PaymentStatus::Pending->value,
                'label' => 'Pending',
            ],
            'proof_submitted' => true,
        ])
        ->has('order.items', 1)
        ->where('order.items.0.id', $item->id)
        ->where('order.items.0.product.name', 'Battlefront Graphics Card')
        ->where('order.items.0.product.brand', 'NVIDIA')
        ->where('order.items.0.quantity', 2)
        ->where('order.items.0.unit_price', '1250.00')
        ->where('order.items.0.line_total', '2500.00')
        ->where('order.item_count', 1)
        ->where('order.total_quantity', 2)
        ->where('order.total', '2500.00')
        ->missing('order.payment_proof_path'));
});

test('customers cannot view another customers order confirmation', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomerOrder = Order::factory()->create();

    $this->actingAs($customer)
        ->get(route('orders.show', $otherCustomerOrder))
        ->assertNotFound();
});

test('refreshing confirmation does not place or deduct an order again', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '1500.00']);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5]);
    (new ManageCart)->add($customer, $product->id, 2);

    $placementResponse = $this->actingAs($customer)->post(route('orders.store'), [
        'recipient_name' => 'Alex Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'cash',
    ]);
    $order = Order::query()->with('items')->sole();

    $placementResponse->assertRedirectToRoute('orders.show', $order);
    $this->get(route('orders.show', $order))->assertOk();
    $this->get(route('orders.show', $order))->assertOk();

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    expect($inventory->refresh()->quantity)->toBe(3)
        ->and($customer->refresh()->cart)->toBeNull();
});
