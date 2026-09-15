<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from administrator order workflows', function () {
    $order = Order::factory()->create();

    $this->get(route('administration.orders.index'))
        ->assertRedirectToRoute('login');
    $this->get(route('administration.orders.show', $order))
        ->assertRedirectToRoute('login');
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Processing->value,
    ])->assertRedirectToRoute('login');
    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Rejected->value,
    ])->assertRedirectToRoute('login');
    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertRedirectToRoute('login');
});

test('customers are forbidden from administrator order workflows', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create();

    $this->actingAs($customer)
        ->get(route('administration.orders.index'))
        ->assertForbidden();
    $this->get(route('administration.orders.show', $order))
        ->assertForbidden();
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Processing->value,
    ])->assertForbidden();
    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Rejected->value,
    ])->assertForbidden();
    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertForbidden();
});

test('administrators can review authoritative order processing details', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/order-proof.png', 'proof');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create([
        'name' => 'Jamie Customer',
        'email' => 'jamie@example.com',
    ]);
    $product = Product::factory()->create([
        'name' => 'Battlefront Graphics Card',
        'brand' => 'NVIDIA',
    ]);
    $order = Order::factory()->for($customer)->delivery()->paidWithGCash()->create([
        'id' => 42,
        'recipient_name' => 'Alex Recipient',
        'contact_number' => '09171234567',
        'delivery_address' => 'Sagay City, Negros Occidental',
        'total_amount' => '2500.00',
        'payment_proof_path' => 'payment-proofs/order-proof.png',
        'created_at' => '2026-09-14 08:30:00',
    ]);
    OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 2,
        'price_at_time' => '1250.00',
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.show', $order));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Show')
        ->where('order.reference', 'BF-000042')
        ->where('order.customer', [
            'id' => $customer->id,
            'name' => 'Jamie Customer',
            'email' => 'jamie@example.com',
        ])
        ->where('order.recipient', [
            'name' => 'Alex Recipient',
            'contact_number' => '09171234567',
        ])
        ->where('order.fulfillment', [
            'value' => FulfillmentMethod::Delivery->value,
            'label' => 'Delivery',
            'delivery_address' => 'Sagay City, Negros Occidental',
        ])
        ->where('order.status', ['value' => 'pending', 'label' => 'Pending'])
        ->where('order.allowed_status_transitions', [
            ['value' => 'processing', 'label' => 'Processing'],
            ['value' => 'cancelled', 'label' => 'Cancelled'],
        ])
        ->where('order.payment.method', [
            'value' => PaymentMethod::GCash->value,
            'label' => 'GCash',
            'requires_proof' => true,
        ])
        ->where('order.payment.proof_submitted', true)
        ->where('order.payment.proof_available', true)
        ->where('order.items.0.product.name', 'Battlefront Graphics Card')
        ->where('order.items.0.quantity', 2)
        ->where('order.items.0.unit_price', '1250.00')
        ->where('order.items.0.line_total', '2500.00')
        ->where('order.total', '2500.00')
        ->missing('order.payment_proof_path'));
});

test('administrator order directory is newest first and paginated', function () {
    $administrator = User::factory()->administrator()->create();
    Order::factory()->count(21)->sequence(
        fn ($sequence): array => [
            'created_at' => now()->subDays(20 - $sequence->index),
        ],
    )->create();

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Index')
        ->where('orders.total', 21)
        ->where('orders.per_page', 20)
        ->has('orders.data', 20)
        ->where('orders.data.0.id', Order::query()->latest('created_at')->latest('id')->value('id'))
        ->missing('orders.data.0.payment_proof_path'));
});

test('administrators can apply approved order status transitions', function (
    OrderStatus $currentStatus,
    OrderStatus $nextStatus,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create([
        'status' => $currentStatus,
        'payment_status' => $nextStatus === OrderStatus::Completed
            ? PaymentStatus::Verified
            : PaymentStatus::Pending,
    ]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => $nextStatus->value,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertInertiaFlash('toast.message', 'Order status updated.');
    expect($order->refresh()->status)->toBe($nextStatus);
})->with([
    'pending to processing' => [OrderStatus::Pending, OrderStatus::Processing],
    'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
    'processing to completed' => [OrderStatus::Processing, OrderStatus::Completed],
    'processing to cancelled' => [OrderStatus::Processing, OrderStatus::Cancelled],
]);

test('pending or rejected payment blocks order completion', function (PaymentStatus $paymentStatus) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::Processing,
        'payment_status' => $paymentStatus,
    ]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Completed->value,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'status' => 'Verify payment before completing this order.',
        ]);
    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
})->with([
    'pending payment' => PaymentStatus::Pending,
    'rejected payment' => PaymentStatus::Rejected,
]);

test('administrators cannot apply unapproved order status transitions', function (
    OrderStatus $currentStatus,
    OrderStatus $nextStatus,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create(['status' => $currentStatus]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => $nextStatus->value,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'status' => 'This order cannot move to the selected status.',
        ]);
    expect($order->refresh()->status)->toBe($currentStatus);
})->with([
    'pending cannot skip processing' => [OrderStatus::Pending, OrderStatus::Completed],
    'processing cannot return to pending' => [OrderStatus::Processing, OrderStatus::Pending],
    'completed is terminal' => [OrderStatus::Completed, OrderStatus::Cancelled],
    'cancelled is terminal' => [OrderStatus::Cancelled, OrderStatus::Processing],
]);

test('invalid order statuses are rejected without changing the order', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => 'shipped',
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors(['status' => 'Select a valid order status.']);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('wallet verification requires manual confirmation and accessible proof', function () {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => 'payment-proofs/missing.png',
    ]);

    $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
        ])
        ->assertSessionHasErrors([
            'manual_verification_confirmed' => 'Confirm the manual payment check before marking this payment as verified.',
        ]);

    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Verified->value,
        'manual_verification_confirmed' => '1',
    ])->assertSessionHasErrors([
        'payment_status' => 'The submitted payment proof is unavailable and cannot be verified.',
    ]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});

test('administrators can manually verify wallet payment evidence', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/verified.png', 'proof');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithMaya()->create([
        'payment_proof_path' => 'payment-proofs/verified.png',
    ]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertInertiaFlash('toast.message', 'Payment status updated.');
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Verified);
});

test('administrators can reject missing or unverifiable payment evidence', function () {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => null,
        'status' => OrderStatus::Processing,
    ]);

    $this->actingAs($administrator)
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
        ])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and($order->status)->toBe(OrderStatus::Processing);
});

test('payment cannot be rejected after order completion', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Pending,
    ]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'payment_status' => 'Payment cannot be rejected after the order is completed.',
        ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->status)->toBe(OrderStatus::Completed);
});

test('invalid payment decisions are rejected without changing payment status', function (string $paymentStatus) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => $paymentStatus,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'payment_status' => 'Select verified or rejected for the payment decision.',
        ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with([
    'pending is not a decision' => PaymentStatus::Pending->value,
    'unknown status' => 'paid',
]);

test('cash and card at store can be verified without online proof', function (PaymentMethod $paymentMethod) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create(['payment_method' => $paymentMethod]);

    $this->actingAs($administrator)
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Verified);
})->with([
    'cash' => PaymentMethod::Cash,
    'card at store' => PaymentMethod::CardAtStore,
]);

test('final payment decisions cannot be changed', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create([
        'payment_status' => PaymentStatus::Rejected,
    ]);

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ]);

    $response->assertSessionHasErrors([
        'payment_status' => 'This payment already has a final decision.',
    ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected);
});

test('only administrators can stream private payment proof', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/private.png', 'private-proof');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => 'payment-proofs/private.png',
    ]);

    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertRedirectToRoute('login');
    $this->actingAs($customer)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertForbidden();
    $this->actingAs($administrator)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertStreamedContent('private-proof');
});

test('unavailable or out of directory proof references return not found', function (string $path) {
    Storage::fake('local');
    Storage::disk('local')->put('other/private.png', 'private-proof');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => $path,
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertNotFound();
})->with([
    'missing file' => 'payment-proofs/missing.png',
    'outside proof directory' => 'other/private.png',
]);
