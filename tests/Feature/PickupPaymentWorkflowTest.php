<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Reporting\MonthlySalesRepository;
use App\Services\Cart\CartService;
use App\Services\Order\OrderProcessingService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function pickupPaymentOrder(PaymentMethod $method, OrderStatus $status = OrderStatus::Pending, PaymentStatus $payment = PaymentStatus::Pending): Order
{
    $order = Order::factory()->create(['payment_method' => $method, 'status' => $status, 'payment_status' => $payment, 'total_amount' => '100.00']);
    $stock = Inventory::factory()->create(['quantity' => 8]);
    OrderItem::factory()->for($order)->create(['product_id' => $stock->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);

    return $order;
}

test('Cash and Card pickup can be processed unpaid and confirmed exactly once without changing stock', function (PaymentMethod $method) {
    $this->travelTo('2026-10-13 09:00:00');
    $order = pickupPaymentOrder($method);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.payment.pay_at_pickup', true)
        ->where('order.payment.can_confirm_pickup', false)
        ->where('order.allowed_status_transitions', [
            ['value' => 'processing', 'label' => 'Processing'], ['value' => 'cancelled', 'label' => 'Cancelled'],
        ]));
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing'])->assertSessionHasNoErrors();
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.payment.can_confirm_pickup', true)
        ->where('order.allowed_status_transitions', [['value' => 'cancelled', 'label' => 'Cancelled']]));

    $months = [['start' => '2026-10-01', 'end_exclusive' => '2026-11-01']];
    $productId = $order->items->sole()->product_id;
    expect(app(MonthlySalesRepository::class)->aggregateProduct($productId, $months))->toBe([]);
    $payload = ['payment_received_confirmed' => true, 'products_handed_over_confirmed' => true, 'total_amount' => '0.01'];
    $this->post(route('administration.orders.pickup-confirmation.store', $order), $payload)->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Payment and product handover confirmed. Pickup completed and sale recorded.');

    expect($order->refresh())->status->toBe(OrderStatus::Completed)->payment_status->toBe(PaymentStatus::Verified);
    expect($order->sale)->amount->toBe('100.00')->sale_date->toDateString()->toBe('2026-10-13');
    expect(Inventory::sole()->quantity)->toBe(8);
    expect(app(MonthlySalesRepository::class)->aggregateProduct($productId, $months))->toBe([['start' => '2026-10-01', 'quantity_sold' => 2]]);
    expect($order->user->notifications()->sole()->data['event'])->toBe('payment.verified');
    $this->post(route('administration.orders.pickup-confirmation.store', $order), $payload)->assertSessionHasErrors('payment_received_confirmed');
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('notifications', 1);
    $this->withToken($order->user->createToken('Phone')->plainTextToken)->getJson('/api/v1/orders/'.$order->id)
        ->assertJsonPath('data.status.value', 'completed')->assertJsonPath('data.status.label', 'Picked up / Completed')
        ->assertJsonPath('data.payment.status.value', 'verified')->assertJsonPath('data.payment.notice', 'Battlefront has confirmed your payment and completed pickup.');
})->with([PaymentMethod::Cash, PaymentMethod::CardAtStore]);

test('pickup confirmation requires both payment receipt and product handover', function (array $payload, array $fields) {
    $order = pickupPaymentOrder(PaymentMethod::CardAtStore, OrderStatus::Processing);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->post(route('administration.orders.pickup-confirmation.store', $order), $payload)->assertSessionHasErrors($fields);
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    'missing confirmations' => [[], [
        'payment_received_confirmed' => 'Confirm that cash was received or the physical card transaction succeeded.',
        'products_handed_over_confirmed' => 'Confirm that the products were handed over to the customer.',
    ]],
    'payment declined' => [['payment_received_confirmed' => false, 'products_handed_over_confirmed' => true], ['payment_received_confirmed']],
    'no handover' => [['payment_received_confirmed' => true, 'products_handed_over_confirmed' => 'no'], ['products_handed_over_confirmed']],
]);

test('pickup confirmation rejects premature terminal rejected prepaid delivery and COD orders', function (array $attributes) {
    $order = pickupPaymentOrder(PaymentMethod::Cash, OrderStatus::Processing);
    $order->update($attributes);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $before = $order->refresh()->getAttributes();

    $this->actingAs($administrator)->post(route('administration.orders.pickup-confirmation.store', $order), [
        'payment_received_confirmed' => true, 'products_handed_over_confirmed' => true,
    ])->assertSessionHasErrors('payment_received_confirmed');
    expect($order->refresh()->getAttributes())->toBe($before);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    'Pending' => [['status' => OrderStatus::Pending]],
    'Completed' => [['status' => OrderStatus::Completed]],
    'Cancelled' => [['status' => OrderStatus::Cancelled]],
    'Rejected' => [['payment_status' => PaymentStatus::Rejected]],
    'GCash' => [['payment_method' => PaymentMethod::GCash]],
    'Maya' => [['payment_method' => PaymentMethod::Maya]],
    'delivery Cash' => [['fulfillment_method' => FulfillmentMethod::Delivery]],
    'COD' => [['payment_method' => PaymentMethod::Cod, 'fulfillment_method' => FulfillmentMethod::Delivery]],
    'unexpected proof' => [['payment_proof_path' => 'payment-proofs/unexpected.jpg']],
]);

test('pickup payment and completion cannot bypass the combined administrator confirmation', function (PaymentMethod $method, string $operation) {
    $order = pickupPaymentOrder($method, OrderStatus::Processing);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    if ($operation === 'completed') {
        $this->patch(route('administration.orders.status.update', $order), ['status' => $operation])->assertSessionHasErrors('status');
    } else {
        $this->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => $operation, 'manual_verification_confirmed' => true,
        ])->assertSessionHasErrors('payment_status');
    }
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    $this->assertDatabaseCount('sales', 0);
})->with([PaymentMethod::Cash, PaymentMethod::CardAtStore])->with(['completed', 'verified', 'rejected']);

test('pickup confirmation is restricted to authenticated web administrators', function (string $caller) {
    $order = pickupPaymentOrder(PaymentMethod::Cash, OrderStatus::Processing);
    if ($caller === 'customer') {
        $this->actingAs($order->user);
    } elseif ($caller === 'bearer') {
        $this->withToken($order->user->createToken('Phone')->plainTextToken);
    }
    $response = $this->post(route('administration.orders.pickup-confirmation.store', $order), [
        'payment_received_confirmed' => true, 'products_handed_over_confirmed' => true,
    ]);
    $caller === 'customer' ? $response->assertForbidden() : $response->assertRedirectToRoute('login');
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    $this->assertDatabaseCount('sales', 0);
})->with(['guest', 'customer', 'bearer']);

test('sale recording failure rolls back pickup payment completion and notification', function () {
    $order = pickupPaymentOrder(PaymentMethod::Cash, OrderStatus::Processing);
    Event::listen('eloquent.created: '.Sale::class, fn (): never => throw new RuntimeException('Sale failure.'));
    try {
        expect(fn () => app(OrderProcessingService::class)->confirmPickup($order, true, true))->toThrow(RuntimeException::class, 'Sale failure.');
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
});

test('legacy verified pickup still requires explicit handover and preserves payment history', function (PaymentMethod $method) {
    $order = pickupPaymentOrder($method, OrderStatus::Processing, PaymentStatus::Verified);
    $service = app(OrderProcessingService::class);
    expect(fn () => $service->updateStatus($order, OrderStatus::Completed))->toThrow(ValidationException::class);
    $service->confirmPickup($order, true, true);
    expect($order->refresh())->payment_status->toBe(PaymentStatus::Verified)->status->toBe(OrderStatus::Completed);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('notifications', 0);
})->with([PaymentMethod::Cash, PaymentMethod::CardAtStore]);

test('stale pickup confirmation and cancellation cannot duplicate sales or restore completed stock', function (bool $cancelFirst) {
    $order = pickupPaymentOrder(PaymentMethod::Cash, OrderStatus::Processing);
    $stale = Order::findOrFail($order->id);
    $service = app(OrderProcessingService::class);
    if ($cancelFirst) {
        $service->updateStatus($order, OrderStatus::Cancelled);
        expect(fn () => $service->confirmPickup($stale, true, true))->toThrow(ValidationException::class);
    } else {
        $service->confirmPickup($order, true, true);
        expect(fn () => $service->confirmPickup($stale, true, true))->toThrow(ValidationException::class);
        expect(fn () => $service->updateStatus($stale, OrderStatus::Cancelled))->toThrow(ValidationException::class);
    }
    expect(Inventory::sole()->quantity)->toBe($cancelFirst ? 10 : 8);
    $this->assertDatabaseCount('sales', $cancelFirst ? 0 : 1);
    $this->assertDatabaseCount('notifications', 1);
})->with([true, false]);

test('pickup checkout web mobile history and administrator views share labels and physical terminal guidance', function (PaymentMethod $method, string $label) {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $item = app(CartService::class)->add($customer, Inventory::factory()->create(['quantity' => 10])->product_id, 2);
    $this->withToken($customer->createToken('Phone')->plainTextToken);
    $response = $this->getJson('/api/v1/checkout?cart_item_ids[]='.$item->id)->assertOk();
    $option = collect($response->json('data.payment_methods'))->firstWhere('value', $method->value);
    expect($option)->label->toBe($label)->requires_proof->toBeFalse()->available_for->toBe(['pickup']);
    expect($option['description'])->toContain($method === PaymentMethod::Cash ? 'Pay cash' : 'physical debit/credit card terminal');
    $this->postJson('/api/v1/orders', [
        'cart_item_ids' => [$item->id], 'recipient_name' => 'Pickup Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup', 'payment_method' => $method->value,
    ])->assertCreated()->assertJsonPath('data.payment.method.label', $label)
        ->assertJsonPath('data.payment.method.description', $option['description']);
    $order = Order::sole();
    expect($order)->status->toBe(OrderStatus::Pending)->payment_status->toBe(PaymentStatus::Pending)->delivery_fee->toBe('0.00')->shipment->toBeNull();
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->getJson('/api/v1/orders')->assertJsonPath('data.0.payment.method.label', $label);
    $this->actingAs($customer)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.payment.method.label', $label));
    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.payment.method.description', $option['description']));
})->with([
    [PaymentMethod::Cash, 'Cash (Pay at Pickup)'],
    [PaymentMethod::CardAtStore, 'Debit/Credit Card (Pay at Store)'],
]);

test('rejected pickup payment cannot be processed and missing internal confirmations leave it unpaid', function (bool $payment, bool $handover) {
    $order = pickupPaymentOrder(PaymentMethod::Cash, OrderStatus::Processing);
    expect(fn () => app(OrderProcessingService::class)->confirmPickup($order, $payment, $handover))->toThrow(ValidationException::class);
    $order->update(['status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Rejected]);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Processing))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('sales', 0);
})->with([[false, true], [true, false]]);

test('prepaid pickup continues requiring verified payment before processing and completion', function (PaymentMethod $method, PaymentStatus $payment) {
    $order = pickupPaymentOrder($method, payment: $payment);
    $service = app(OrderProcessingService::class);
    if ($payment === PaymentStatus::Verified) {
        $service->updateStatus($order, OrderStatus::Processing);
        $service->updateStatus($order, OrderStatus::Completed);
        expect($order->refresh()->status)->toBe(OrderStatus::Completed);
        $this->assertDatabaseCount('sales', 1);
    } else {
        expect(fn () => $service->updateStatus($order, OrderStatus::Processing))->toThrow(ValidationException::class);
        $order->update(['status' => OrderStatus::Processing]);
        expect(fn () => $service->updateStatus($order, OrderStatus::Completed))->toThrow(ValidationException::class);
        $this->assertDatabaseCount('sales', 0);
    }
    expect(Inventory::sole()->quantity)->toBe(8);
})->with([PaymentMethod::GCash, PaymentMethod::Maya])->with([PaymentStatus::Pending, PaymentStatus::Rejected, PaymentStatus::Verified]);
