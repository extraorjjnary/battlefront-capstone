<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Inertia\Testing\AssertableInertia as Assert;

test('customer submitted details expose the saved destination consistently on web and API', function (string $scenario, ?string $destination, ?string $address) {
    $factory = match ($scenario) {
        'quoted delivery' => Order::factory()->withDeliverySnapshot('Calatrava'),
        'legacy delivery' => Order::factory()->delivery(),
        default => Order::factory(),
    };
    $order = $factory->create(['delivery_address' => 'Amihan 1, Bridge Area']);
    config(['battlefront.delivery.destinations' => []]);
    $this->actingAs($order->user)->withToken($order->user->createToken('Phone')->plainTextToken);

    $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.fulfillment.delivery_address', $address)
        ->where('order.fulfillment.delivery_destination', $destination));
    $this->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.fulfillment.delivery_address', $address)
        ->assertJsonPath('data.fulfillment.delivery_destination', $destination);
})->with([
    'quoted delivery' => ['quoted delivery', 'Calatrava', 'Amihan 1, Bridge Area'],
    'legacy delivery' => ['legacy delivery', null, 'Amihan 1, Bridge Area'],
    'pickup' => ['pickup', null, null],
]);

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
            'id' => 42,
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
        ->where('order.reference', 'BF-000042')
        ->where('order.status', ['value' => 'pending', 'label' => 'Pending'])
        ->where('order.recipient', [
            'name' => 'Alex Customer',
            'contact_number' => '09171234567',
        ])
        ->where('order.fulfillment', [
            'value' => FulfillmentMethod::Delivery->value,
            'label' => 'Delivery',
            'delivery_address' => 'Sagay City, Negros Occidental',
            'delivery_destination' => null,
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
            'notice' => 'Your uploaded proof is awaiting manual verification by Battlefront.',
            'rejection' => null,
            'can_resubmit_proof' => false,
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
        ->where('isConfirmation', false)
        ->missing('order.payment_proof_path'));
});

test('customer order details use fulfillment-specific status labels', function (
    FulfillmentMethod $fulfillmentMethod,
    OrderStatus $status,
    string $label,
) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'fulfillment_method' => $fulfillmentMethod,
        'delivery_address' => $fulfillmentMethod === FulfillmentMethod::Delivery
            ? 'Sagay City, Negros Occidental'
            : null,
        'status' => $status,
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', [
                'value' => $status->value,
                'label' => $label,
            ]));
})->with([
    'pickup pending' => [FulfillmentMethod::Pickup, OrderStatus::Pending, 'Pending'],
    'pickup processing' => [FulfillmentMethod::Pickup, OrderStatus::Processing, 'Preparing for pickup'],
    'pickup completed' => [FulfillmentMethod::Pickup, OrderStatus::Completed, 'Picked up / Completed'],
    'pickup cancelled' => [FulfillmentMethod::Pickup, OrderStatus::Cancelled, 'Cancelled'],
    'delivery pending' => [FulfillmentMethod::Delivery, OrderStatus::Pending, 'Pending'],
    'delivery processing' => [FulfillmentMethod::Delivery, OrderStatus::Processing, 'Preparing for delivery'],
    'delivery completed' => [FulfillmentMethod::Delivery, OrderStatus::Completed, 'Delivered / Completed'],
    'delivery cancelled' => [FulfillmentMethod::Delivery, OrderStatus::Cancelled, 'Cancelled'],
]);

test('customers see wallet rejection feedback and the resubmission capability', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->withRejectedPaymentProof()->create([
        'status' => OrderStatus::Processing,
        'payment_rejection_reason' => PaymentRejectionReason::AmountMismatch,
        'payment_rejection_note' => 'The receipt shows a lower total than the order.',
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.payment.status.value', PaymentStatus::Rejected->value)
            ->where('order.payment.rejection', [
                'reason' => 'Payment amount does not match',
                'note' => 'The receipt shows a lower total than the order.',
            ])
            ->where('order.payment.can_resubmit_proof', true)
            ->missing('order.payment_proof_path'));
});

test('customers see safe fallback feedback when a legacy rejection has no usable explanation', function (
    ?PaymentRejectionReason $reason,
    ?string $note,
) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithMaya()->withRejectedPaymentProof()->create([
        'payment_rejection_reason' => $reason,
        'payment_rejection_note' => $note,
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.payment.rejection', [
                'reason' => 'Battlefront could not verify the submitted payment proof.',
                'note' => null,
            ])
            ->where('order.payment.can_resubmit_proof', true));
})->with([
    'missing reason' => [null, null],
    'other without a note' => [PaymentRejectionReason::Other, '   '],
]);

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
    (new CartService)->add($customer, $product->id, 2);

    $placementResponse = $this->actingAs($customer)->post(route('orders.store'), [
        'recipient_name' => 'Alex Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'cash',
    ]);
    $order = Order::query()->with('items')->sole();

    $placementResponse->assertRedirectToRoute('orders.show', $order);
    $this->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('isConfirmation', true));
    $this->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('isConfirmation', false));

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    expect($inventory->refresh()->quantity)->toBe(3)
        ->and($customer->refresh()->cart)->toBeNull();
});
