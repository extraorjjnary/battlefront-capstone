<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function shipmentWorkflowOrder(OrderStatus $status = OrderStatus::Processing, PaymentStatus $payment = PaymentStatus::Verified): Order
{
    $order = Order::factory()->withDeliverySnapshot('Bacolod City')->create(['status' => $status, 'payment_status' => $payment]);
    Shipment::factory()->for($order)->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '50.00']);

    return $order->refresh();
}

function advanceShipmentForTest(Order $order, ShipmentStatus $target): void
{
    $processing = app(OrderProcessingService::class);
    while ($order->refresh()->shipment->status !== $target) {
        $processing->updateShipmentStatus($order, $order->shipment->status->next());
    }
}

test('administrators advance the full manual lifecycle and complete one sale without deducting stock', function () {
    $this->travelTo('2026-10-08 09:00:00');
    Storage::fake('local');
    $order = shipmentWorkflowOrder(OrderStatus::Pending, PaymentStatus::Pending);
    Storage::disk('local')->put($order->payment_proof_path, 'evidence');
    $stock = Inventory::sole();
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator);

    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => 'verified', 'manual_verification_confirmed' => true,
    ])->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing'])->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);

    $milestones = [
        'preparing' => 'preparing_at',
        'ready_for_dispatch' => 'ready_for_dispatch_at',
        'handed_to_lbc' => 'handed_to_carrier_at',
        'in_transit' => 'in_transit_at',
        'out_for_delivery' => 'out_for_delivery_at',
        'delivered' => 'delivered_at',
    ];
    foreach ($milestones as $status => $timestamp) {
        $this->travel(1)->hours();
        $submission = ['status' => $status];
        if ($status === 'handed_to_lbc') {
            $submission['tracking_reference'] = ' ACTUAL-LBC-REFERENCE ';
        }
        $this->patch(route('administration.orders.shipment.status.update', $order), $submission)
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Shipment status updated.');
        expect($order->refresh()->shipment->status->value)->toBe($status);
        expect($order->shipment->getAttribute($timestamp)->toDateTimeString())->toBe(now()->toDateTimeString());
        expect($order->status)->toBe($status === 'delivered' ? OrderStatus::Completed : OrderStatus::Processing);
    }

    expect($order->shipment->tracking_reference)->toBe('ACTUAL-LBC-REFERENCE');
    expect($stock->refresh()->quantity)->toBe(5);
    expect($order->sale)->amount->toBe('350.00');
    $this->assertDatabaseCount('sales', 1);
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])->assertSessionHasErrors('status');
    $this->assertDatabaseCount('sales', 1);
});

test('shipment preparation requires both verified payment and a processing order', function (OrderStatus $status, PaymentStatus $payment) {
    $order = shipmentWorkflowOrder($status, $payment);
    $before = $order->shipment->getAttributes();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'preparing'])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    expect($order->status)->toBe($status);
    expect(Inventory::sole()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'payment pending' => [OrderStatus::Processing, PaymentStatus::Pending],
    'payment rejected' => [OrderStatus::Processing, PaymentStatus::Rejected],
    'order pending' => [OrderStatus::Pending, PaymentStatus::Verified],
    'order cancelled' => [OrderStatus::Cancelled, PaymentStatus::Verified],
    'previously completed order' => [OrderStatus::Completed, PaymentStatus::Verified],
]);

test('shipment rejects skips backwards repeats and terminal transitions', function (ShipmentStatus $initial, string $requested) {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, $initial);
    $before = $order->shipment->getAttributes();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.status.update', $order), ['status' => $requested])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with([
    'skip' => [ShipmentStatus::AwaitingPreparation, 'ready_for_dispatch'],
    'backwards' => [ShipmentStatus::ReadyForDispatch, 'preparing'],
    'repeat' => [ShipmentStatus::Preparing, 'preparing'],
    'manual cancellation outside order workflow' => [ShipmentStatus::Preparing, 'cancelled'],
    'terminal backwards' => [ShipmentStatus::Delivered, 'out_for_delivery'],
    'terminal repeat' => [ShipmentStatus::Delivered, 'delivered'],
    'unknown' => [ShipmentStatus::AwaitingPreparation, 'booked'],
]);

test('pickup and legacy orders reject shipment mutations without creating shipment records', function (bool $delivery, string $endpoint) {
    $factory = Order::factory();
    $order = ($delivery ? $factory->delivery() : $factory)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified]);
    $payload = $endpoint === 'status' ? ['status' => 'preparing'] : ['tracking_reference' => 'SUPPLIED'];

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.'.$endpoint.'.update', $order), $payload)
        ->assertSessionHasErrors($endpoint === 'status' ? 'status' : 'tracking_reference');

    $this->assertDatabaseCount('shipments', 0);
    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
})->with(['pickup' => [false], 'legacy delivery' => [true]])->with(['status', 'reference']);

test('shipment endpoints reject unauthenticated and customer mutations', function (string $access, string $endpoint) {
    $order = shipmentWorkflowOrder();
    $before = $order->shipment->getAttributes();
    if ($access === 'customer') {
        $this->actingAs($order->user);
    } elseif ($access === 'bearer') {
        $this->withToken($order->user->createToken('Phone')->plainTextToken);
    }
    $response = $this->patch(route('administration.orders.shipment.'.$endpoint.'.update', $order), [
        'status' => 'preparing', 'tracking_reference' => 'SUPPLIED',
    ]);
    if ($access === 'customer') {
        $response->assertForbidden();
    } else {
        $response->assertRedirectToRoute('login');
    }

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with(['guest', 'customer', 'bearer'])->with(['status', 'reference']);

test('direct order completion cannot bypass delivery milestones', function () {
    $order = shipmentWorkflowOrder();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])
        ->assertSessionHasErrors(['status' => 'Complete delivery through the shipment Delivered milestone.']);

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);
    $this->assertDatabaseCount('sales', 0);
});

test('cancelling an eligible delivery terminates its shipment and restores stock exactly once', function (ShipmentStatus $milestone, OrderStatus $status) {
    $order = shipmentWorkflowOrder($status);
    advanceShipmentForTest($order, $milestone);
    $shipment = $order->shipment->getAttributes();
    $this->actingAs(User::factory()->administrator()->create());

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->shipment)->status->toBe(ShipmentStatus::Cancelled)->cancelled_at->not->toBeNull();
    expect($order->shipment->handed_to_carrier_at?->toDateTimeString())->toBe($shipment['handed_to_carrier_at']);
    expect(Inventory::sole()->quantity)->toBe(7);

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'SUPPLIED'])->assertSessionHasErrors('tracking_reference');
    expect(Inventory::sole()->quantity)->toBe(7);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'pending before packing' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Pending],
    'processing before packing' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Processing],
    'preparing' => [ShipmentStatus::Preparing, OrderStatus::Processing],
    'ready' => [ShipmentStatus::ReadyForDispatch, OrderStatus::Processing],
    'handed over' => [ShipmentStatus::HandedToLbc, OrderStatus::Processing],
    'in transit' => [ShipmentStatus::InTransit, OrderStatus::Processing],
    'out for delivery' => [ShipmentStatus::OutForDelivery, OrderStatus::Processing],
]);

test('sale failure rolls back shipment delivery order completion and timestamps together', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::OutForDelivery);
    Event::listen('eloquent.created: '.Sale::class, fn (): never => throw new RuntimeException('Sale failure.'));

    try {
        expect(fn () => app(OrderProcessingService::class)->updateShipmentStatus($order, ShipmentStatus::Delivered))
            ->toThrow(RuntimeException::class, 'Sale failure.');
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment)->status->toBe(ShipmentStatus::OutForDelivery)->delivered_at->toBeNull();
    expect(Inventory::sole()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 0);
});

test('shipment cancellation failure rolls back order stock and shipment together', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::InTransit);
    Event::listen('eloquent.updated: '.Shipment::class, fn (): never => throw new RuntimeException('Shipment failure.'));

    try {
        expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled))
            ->toThrow(RuntimeException::class, 'Shipment failure.');
    } finally {
        Event::forget('eloquent.updated: '.Shipment::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment)->status->toBe(ShipmentStatus::InTransit)->cancelled_at->toBeNull();
    expect(Inventory::sole()->quantity)->toBe(5);
});

test('operational ETA is persisted at preparation and survives later time and configuration changes', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo('2028-02-27 17:00:00 UTC');
    $order = shipmentWorkflowOrder();
    $processing = app(OrderProcessingService::class);

    $processing->updateShipmentStatus($order, ShipmentStatus::Preparing);
    expect($order->refresh()->shipment->eta_anchor_date->toDateString())->toBe('2028-02-28');
    expect($order->shipment->estimated_delivery_start->toDateString())->toBe('2028-03-01');
    expect($order->shipment->estimated_delivery_end->toDateString())->toBe('2028-03-02');
    expect($order->shipment->eta_timezone)->toBe('Asia/Manila');
    config(['app.timezone' => 'UTC', 'battlefront.delivery.carrier' => 'changed']);
    $this->travel(10)->days();
    $processing->updateShipmentStatus($order, ShipmentStatus::ReadyForDispatch);

    expect($order->refresh()->shipment->estimated_delivery_start->toDateString())->toBe('2028-03-01');
    expect($order->shipment->eta_timezone)->toBe('Asia/Manila');
    expect($order->shipment->carrier)->toBe('lbc');
    expect(fn () => $order->shipment->update(['estimated_delivery_start' => '2028-03-20']))
        ->toThrow(DomainException::class, 'The operational shipment estimate cannot be changed.');
});

test('real references may be supplied at handoff and corrected or cleared without changing milestones', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::ReadyForDispatch);
    $this->actingAs(User::factory()->administrator()->create());
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'handed_to_lbc', 'tracking_reference' => ' REAL-REFERENCE '])
        ->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->tracking_reference)->toBe('REAL-REFERENCE');
    $handoff = $order->shipment->handed_to_carrier_at->toDateTimeString();

    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'CORRECTED'])->assertSessionHasNoErrors();
    expect($order->refresh()->shipment)->tracking_reference->toBe('CORRECTED')->status->toBe(ShipmentStatus::HandedToLbc);
    expect($order->shipment->handed_to_carrier_at->toDateTimeString())->toBe($handoff);
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => '  '])->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->tracking_reference)->toBeNull();

    advanceShipmentForTest($order, ShipmentStatus::Delivered);
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'REAL-LATE-REFERENCE'])->assertSessionHasNoErrors();
    expect($order->refresh()->shipment)->tracking_reference->toBe('REAL-LATE-REFERENCE')->status->toBe(ShipmentStatus::Delivered);
    $this->assertDatabaseCount('sales', 1);
});

test('references reject early entry and malformed values', function (mixed $reference, bool $handoff) {
    $order = shipmentWorkflowOrder();
    if ($handoff) {
        advanceShipmentForTest($order, ShipmentStatus::HandedToLbc);
    }

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => $reference])
        ->assertSessionHasErrors('tracking_reference');

    expect($order->refresh()->shipment->tracking_reference)->toBeNull();
})->with([
    'before handoff' => ['REAL-REFERENCE', false],
    'too long' => [str_repeat('x', 256), true],
    'array' => [['forged'], true],
]);

test('shipment progression ignores submitted carrier timestamps ETA and order status', function () {
    $order = shipmentWorkflowOrder();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.status.update', $order), [
            'status' => 'preparing', 'carrier' => 'forged', 'preparing_at' => '2000-01-01',
            'estimated_delivery_start' => '2000-01-01', 'order_status' => 'completed', 'delivery_fee' => '0.00',
        ])->assertSessionHasNoErrors();

    expect($order->refresh())->status->toBe(OrderStatus::Processing)->delivery_fee->toBe('250.00');
    expect($order->shipment)->carrier->toBe('lbc')->status->toBe(ShipmentStatus::Preparing);
    expect($order->shipment->preparing_at->toDateString())->toBe(now()->toDateString());
    $this->assertDatabaseCount('sales', 0);
});

test('a reference supplied before handoff rejects the entire milestone update', function () {
    $order = shipmentWorkflowOrder();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('administration.orders.shipment.status.update', $order), [
            'status' => 'preparing', 'tracking_reference' => 'SUPPLIED-TOO-EARLY',
        ])->assertSessionHasErrors('tracking_reference');

    expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation)
        ->tracking_reference->toBeNull()->preparing_at->toBeNull()->eta_anchor_date->toBeNull();
});

test('customer mobile API has no shipment mutation endpoint', function (string $endpoint) {
    $order = shipmentWorkflowOrder();
    $before = $order->shipment->getAttributes();

    $this->withToken($order->user->createToken('Phone')->plainTextToken)
        ->patch('/api/v1/orders/'.$order->id.'/shipment/'.$endpoint, ['status' => 'delivered', 'tracking_reference' => 'FORGED'])
        ->assertNotFound();

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with(['status', 'reference']);
