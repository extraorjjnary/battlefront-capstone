<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{Order, User} */
function handoffOrder(PaymentMethod $method = PaymentMethod::GCash): array
{
    $handler = User::factory()->administrator()->create();
    $order = Order::factory()->withDeliverySnapshot('1380300000')->create([
        'status' => OrderStatus::Processing,
        'payment_method' => $method,
        'payment_proof_path' => $method === PaymentMethod::Cod ? null : 'payment-proofs/legacy.jpg',
        'payment_status' => $method === PaymentMethod::Cod ? PaymentStatus::Pending : PaymentStatus::Verified,
        'assigned_handler_id' => $handler->id,
    ]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::ReadyForDispatch]);

    return [$order->refresh(), $handler];
}

/** @return array<string, mixed> */
function handoffPayload(): array
{
    return [
        'status' => 'handed_to_lbc', 'tracking_reference' => ' LBC-HANDOFF-123 ',
        'handoff' => [
            'package_count' => 2, 'package_weight_kg' => 1.25,
            'package_length_cm' => 30, 'package_width_cm' => 20, 'package_height_cm' => 10,
            'handoff_mode' => 'branch_dropoff', 'carrier_cost_reference' => ' RECEIPT-123 ',
            'carrier_cost_confirmed' => true,
        ],
    ];
}

test('handoff atomically records package receipt tracking and one milestone for prepaid and COD', function (PaymentMethod $method) {
    $this->travelTo('2026-11-01 10:00:00');
    Http::preventStrayRequests();
    [$order, $handler] = handoffOrder($method);
    $commercial = $order->getAttributes();

    $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), handoffPayload())
        ->assertSessionHasNoErrors()->assertRedirect();

    $shipment = $order->refresh()->shipment;
    expect($shipment)->status->toBe(ShipmentStatus::HandedToLbc)
        ->package_count->toBe(2)->package_weight_kg->toBe('1.250')
        ->package_length_cm->toBe('30.00')->package_width_cm->toBe('20.00')->package_height_cm->toBe('10.00')
        ->declared_value->toBe($order->product_subtotal)->actual_carrier_cost->toBe($order->delivery_fee)
        ->carrier_cost_reference->toBe('RECEIPT-123')->tracking_reference->toBe('LBC-HANDOFF-123');
    expect($shipment->handed_to_carrier_at->toDateTimeString())->toBe('2026-11-01 10:00:00');
    expect($shipment->carrier_cost_recorded_at->toDateTimeString())->toBe('2026-11-01 10:00:00');
    expect($order->getAttributes())->toBe($commercial);
    expect($order->user->notifications()->pluck('data')->pluck('event')->all())->toBe(['shipment.handed_to_lbc']);
    $this->assertDatabaseCount('sales', 0);
    $snapshot = $shipment->getAttributes();
    $this->patch(route('administration.orders.shipment.status.update', $order), handoffPayload())->assertSessionHasErrors('status');
    expect($shipment->refresh()->getAttributes())->toBe($snapshot);
    $this->assertDatabaseCount('notifications', 1);
    Http::assertNothingSent();
})->with([PaymentMethod::GCash, PaymentMethod::Cod]);

test('invalid handoff evidence leaves package reconciliation audit and status unchanged', function (string $field, mixed $value, string $error) {
    [$order, $handler] = handoffOrder();
    $before = $order->shipment->getAttributes();
    $payload = handoffPayload();
    data_set($payload, $field, $value);

    $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), $payload)
        ->assertSessionHasErrors($error);

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'zero weight' => ['handoff.package_weight_kg', 0, 'handoff.package_weight_kg'],
    'missing dimension' => ['handoff.package_height_cm', null, 'handoff.package_height_cm'],
    'parcel count limit' => ['handoff.package_count', 100, 'handoff.package_count'],
    'no receipt' => ['handoff.carrier_cost_reference', '  ', 'handoff.carrier_cost_reference'],
    'no confirmation' => ['handoff.carrier_cost_confirmed', false, 'handoff.carrier_cost_confirmed'],
    'no tracking after recording operations' => ['tracking_reference', null, 'tracking_reference'],
    'invalid tracking after recording operations' => ['tracking_reference', str_repeat('x', 256), 'tracking_reference'],
    'injected declared value' => ['handoff.declared_value', '100.00', 'handoff'],
    'injected carrier cost' => ['handoff.actual_carrier_cost', '200.00', 'handoff'],
]);

test('standalone package and cost updates are rejected on every shipment stage', function (ShipmentStatus $status) {
    [$order, $handler] = handoffOrder();
    $order->shipment()->update(['status' => $status]);
    $before = $order->refresh()->shipment->getAttributes();

    $this->actingAs($handler)->patch(route('administration.orders.shipment.operations.update', $order), handoffPayload()['handoff'])
        ->assertSessionHasErrors('package_count');
    expect(fn () => app(OrderProcessingService::class)->updateShipmentOperations($order, ['carrier_cost_reference' => 'BYPASS'], $handler))
        ->toThrow(ValidationException::class);

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with(ShipmentStatus::cases());

test('handoff rejects early stale cancelled unpaid and unauthorized submissions', function (string $scenario) {
    [$order, $handler] = handoffOrder();
    if ($scenario === 'early') {
        $order->shipment()->update(['status' => ShipmentStatus::Preparing]);
    } elseif ($scenario === 'cancelled') {
        $order->update(['status' => OrderStatus::Cancelled]);
    } elseif ($scenario === 'unpaid') {
        $order->update(['payment_status' => PaymentStatus::Pending]);
    } elseif ($scenario === 'other handler') {
        $order->assigned_handler_id = User::factory()->administrator()->create()->id;
        $order->save();
    }
    $before = $order->refresh()->shipment->getAttributes();

    $response = $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), handoffPayload());
    $scenario === 'other handler' ? $response->assertForbidden() : $response->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    $this->assertDatabaseCount('notifications', 0);
})->with(['early', 'cancelled', 'unpaid', 'other handler']);

test('existing confirmed receipt and tracking are preserved when missing package records are supplied', function () {
    [$order, $handler] = handoffOrder();
    $order->shipment()->update([
        'actual_carrier_cost' => $order->delivery_fee, 'carrier_cost_reference' => 'OLD-RECEIPT',
        'carrier_cost_recorded_at' => '2026-10-31 09:00:00', 'tracking_reference' => 'OLD-TRACKING',
    ]);
    $payload = handoffPayload();
    unset($payload['tracking_reference'], $payload['handoff']['carrier_cost_reference'], $payload['handoff']['carrier_cost_confirmed']);

    $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), $payload)->assertSessionHasNoErrors();

    expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::HandedToLbc)
        ->carrier_cost_reference->toBe('OLD-RECEIPT')->tracking_reference->toBe('OLD-TRACKING');
    expect($order->shipment->carrier_cost_recorded_at->toDateTimeString())->toBe('2026-10-31 09:00:00');
});

test('handoff fields cannot accompany other milestones and issues remain separately editable', function () {
    [$order, $handler] = handoffOrder();
    $payload = handoffPayload();
    $payload['status'] = 'in_transit';
    $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), $payload)->assertSessionHasErrors('handoff');

    $this->patch(route('administration.orders.shipment.operations.update', $order), [
        'shipment_exception_code' => 'other', 'shipment_exception_note' => 'Carrier receipt requires review.',
    ])->assertSessionHasNoErrors();

    expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::ReadyForDispatch)->shipment_exception_code->toBe('other');
    $this->patch(route('administration.orders.shipment.status.update', $order), handoffPayload())->assertSessionHasErrors('status');
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.shipment_actions.can_update_package_details', false)
        ->where('order.shipment_actions.can_record_carrier_cost', false));
});

test('handoff cannot replace confirmed evidence or conceal historical carrier discrepancies', function (string $scenario, string $error) {
    [$order, $handler] = handoffOrder();
    $order->shipment()->update([
        'actual_carrier_cost' => $scenario === 'cost discrepancy' ? '1.00' : $order->delivery_fee,
        'carrier_cost_reference' => 'RECEIPT-123', 'carrier_cost_recorded_at' => now(),
        'tracking_reference' => $scenario === 'tracking replacement' ? 'ORIGINAL-TRACKING' : null,
    ]);
    $before = $order->refresh()->shipment->getAttributes();
    $payload = handoffPayload();
    if ($scenario === 'receipt replacement') {
        $payload['handoff']['carrier_cost_reference'] = 'REPLACEMENT-RECEIPT';
    }

    $this->actingAs($handler)->patch(route('administration.orders.shipment.status.update', $order), $payload)
        ->assertSessionHasErrors($error);

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    ['receipt replacement', 'handoff.carrier_cost_reference'],
    ['tracking replacement', 'tracking_reference'],
    ['cost discrepancy', 'status'],
]);
