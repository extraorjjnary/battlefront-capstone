<?php

use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @param array<string, mixed> $attributes */
function customerCancellationOrder(array $attributes = [], bool $delivery = false): Order
{
    $factory = $delivery ? Order::factory()->withDeliverySnapshot(subtotal: '20.30') : Order::factory();
    $order = $factory->create($attributes + ['payment_proof_path' => null]);
    if ($delivery) {
        Shipment::factory()->for($order)->create();
    }
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2, 'price_at_time' => '10.15']);

    return $order->refresh();
}

test('customers cancel eligible pending orders without changing their commercial or payment records', function (PaymentMethod $method, bool $delivery) {
    $order = customerCancellationOrder(['payment_method' => $method], $delivery);
    $original = $order->getAttributes();
    $this->actingAs($order->user)->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.can_cancel', true));

    $this->post(route('orders.cancel.store', $order), ['status' => 'completed', 'payment_status' => 'verified'])
        ->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Order cancelled successfully.');

    $order->refresh();
    expect($order->getAttributes())->toBe(array_replace($original, ['status' => 'cancelled']));
    expect(Inventory::sole()->quantity)->toBe(5);
    expect($order->user->notifications()->sole()->data['event'])->toBe('order.cancelled');
    if ($delivery) {
        expect($order->shipment)->status->toBe(ShipmentStatus::Cancelled)->cancelled_at->not->toBeNull();
    }
    $this->assertDatabaseCount('sales', 0);
    $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.status.value', 'cancelled')->where('order.can_cancel', false)
        ->where('order.payment.notice', 'This order is cancelled. Cancellation does not refund or reverse any recorded payment.'));
    $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page
        ->where('orders.data.0.id', $order->id)->where('orders.data.0.status.value', 'cancelled'));
})->with([
    'cash pickup' => [PaymentMethod::Cash, false],
    'card pickup' => [PaymentMethod::CardAtStore, false],
    'proof-free GCash pickup' => [PaymentMethod::GCash, false],
    'proof-free Maya pickup' => [PaymentMethod::Maya, false],
    'proof-free delivery' => [PaymentMethod::GCash, true],
]);

test('customers cannot cancel ineligible order or payment states and stock remains unchanged', function (array $attributes) {
    $order = customerCancellationOrder($attributes);
    $original = $order->getAttributes();
    $this->actingAs($order->user)->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.can_cancel', false));
    $this->from(route('orders.show', $order))->post(route('orders.cancel.store', $order))
        ->assertRedirect(route('orders.show', $order))->assertSessionHasErrors('status');

    expect($order->refresh()->getAttributes())->toBe($original);
    expect(Inventory::sole()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    'processing' => [['status' => OrderStatus::Processing]],
    'completed' => [['status' => OrderStatus::Completed]],
    'cancelled' => [['status' => OrderStatus::Cancelled]],
    'cash verified' => [['payment_status' => PaymentStatus::Verified]],
    'card rejected' => [['payment_method' => PaymentMethod::CardAtStore, 'payment_status' => PaymentStatus::Rejected]],
    'GCash proof submitted but unavailable' => [['payment_method' => PaymentMethod::GCash, 'payment_proof_path' => 'payment-proofs/missing.jpg']],
    'Maya proof submitted' => [['payment_method' => PaymentMethod::Maya, 'payment_proof_path' => 'payment-proofs/maya.jpg']],
    'GCash verified without proof' => [['payment_method' => PaymentMethod::GCash, 'payment_status' => PaymentStatus::Verified]],
    'Maya rejected without proof' => [['payment_method' => PaymentMethod::Maya, 'payment_status' => PaymentStatus::Rejected]],
]);

test('customers cannot cancel once their shipment has progressed', function (ShipmentStatus $status) {
    $order = customerCancellationOrder(delivery: true);
    $order->shipment->update(['status' => $status]);
    $original = $order->refresh()->shipment->getAttributes();
    $this->actingAs($order->user)->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.can_cancel', false));
    $this->post(route('orders.cancel.store', $order))->assertSessionHasErrors('status');
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    expect($order->shipment->getAttributes())->toBe($original);
    expect(Inventory::sole()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc,
    ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered, ShipmentStatus::Cancelled,
]);

test('customer cancellation conceals foreign missing and nonnumeric orders', function (string $target) {
    $order = customerCancellationOrder();
    $customer = User::factory()->customer()->create();
    $id = match ($target) {
        'foreign' => $order->id,
        'missing' => $order->id + 100,
        'nonnumeric' => 'invalid',
    };
    $this->actingAs($customer)->post(route('orders.cancel.store', $id))->assertNotFound();
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    expect(Inventory::sole()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
})->with(['foreign', 'missing', 'nonnumeric']);

test('web cancellation requires customer authentication', function (bool $administrator) {
    $order = customerCancellationOrder();
    if ($administrator) {
        $this->actingAs(User::factory()->administrator()->create());
        $this->post(route('orders.cancel.store', $order))->assertForbidden();
    } else {
        $this->post(route('orders.cancel.store', $order))->assertRedirect(route('login'));
    }
    expect(Inventory::sole()->quantity)->toBe(3);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with([false, true]);

test('admin and customer cancellation cannot restore stock twice with stale models', function (bool $customerFirst) {
    $order = customerCancellationOrder(delivery: true);
    $staleOrder = Order::query()->with('shipment')->findOrFail($order->id);
    $service = app(OrderProcessingService::class);
    if ($customerFirst) {
        $service->cancelForCustomer($order->user, $order->id);
        expect(fn () => $service->updateStatus($staleOrder, OrderStatus::Cancelled))->toThrow(ValidationException::class);
    } else {
        $service->updateStatus($order, OrderStatus::Cancelled);
        expect(fn () => $service->cancelForCustomer($staleOrder->user, $staleOrder->id))->toThrow(ValidationException::class);
    }
    expect(Inventory::sole()->quantity)->toBe(5);
    expect($order->refresh()->shipment->status)->toBe(ShipmentStatus::Cancelled);
    expect($order->user->notifications()->count())->toBe(1);
})->with([true, false]);

test('pickup processing and customer cancellation recheck the winning state', function (bool $cancellationFirst) {
    $order = customerCancellationOrder();
    $staleOrder = Order::query()->findOrFail($order->id);
    $service = app(OrderProcessingService::class);
    if ($cancellationFirst) {
        $service->cancelForCustomer($order->user, $order->id);
        expect(fn () => $service->updateStatus($staleOrder, OrderStatus::Processing))->toThrow(ValidationException::class);
        expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
        expect(Inventory::sole()->quantity)->toBe(5);
    } else {
        $service->updateStatus($order, OrderStatus::Processing);
        expect(fn () => $service->cancelForCustomer($staleOrder->user, $staleOrder->id))->toThrow(ValidationException::class);
        expect($order->refresh()->status)->toBe(OrderStatus::Processing);
        expect($order->payment_status)->toBe(PaymentStatus::Pending);
        expect(Inventory::sole()->quantity)->toBe(3);
    }
    expect($order->user->notifications()->count())->toBe($cancellationFirst ? 1 : 0);
})->with([true, false]);

test('cancelled orders reject both manual payment decisions through administrator endpoints', function (string $decision) {
    $order = customerCancellationOrder();
    app(OrderProcessingService::class)->cancelForCustomer($order->user, $order->id);
    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => $decision, 'manual_verification_confirmed' => true,
        ])->assertSessionHasErrors(['payment_status' => 'Cancelled orders cannot accept payment decisions.']);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
    expect(Inventory::sole()->quantity)->toBe(5);
    expect($order->user->notifications()->count())->toBe(1);
})->with(['verified', 'rejected']);

test('cancelled orders cannot accept replacement wallet proof', function () {
    $order = customerCancellationOrder(['payment_method' => PaymentMethod::GCash, 'payment_status' => PaymentStatus::Rejected]);
    $service = app(OrderProcessingService::class);
    $service->updateStatus($order, OrderStatus::Cancelled);
    expect(fn () => app(ResubmitPaymentProof::class)->execute($order->user, $order, 'payment-proofs/replacement.jpg'))
        ->toThrow(ValidationException::class);
    expect($order->refresh()->payment_proof_path)->toBeNull();
    expect($order->payment_status)->toBe(PaymentStatus::Rejected);
    expect(Inventory::sole()->quantity)->toBe(5);
    expect($order->user->notifications()->count())->toBe(1);
});

test('customer cancellation failure rolls back inventory shipment and notification changes', function () {
    $order = customerCancellationOrder(delivery: true);
    $original = $order->shipment->getAttributes();
    Event::listen('eloquent.updated: '.Order::class, fn (): never => throw new RuntimeException('Cancellation failure.'));
    try {
        expect(fn () => app(OrderProcessingService::class)->cancelForCustomer($order->user, $order->id))
            ->toThrow(RuntimeException::class, 'Cancellation failure.');
    } finally {
        Event::forget('eloquent.updated: '.Order::class);
    }
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    expect($order->shipment->getAttributes())->toBe($original);
    expect(Inventory::sole()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
});
