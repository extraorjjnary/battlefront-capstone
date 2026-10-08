<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Inertia\Testing\AssertableInertia as Assert;

function shipmentPresentationOrder(): Order
{
    $order = Order::factory()->withDeliverySnapshot('Bacolod City')->create([
        'status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified,
    ]);
    Shipment::factory()->for($order)->create(['created_at' => '2026-10-08 08:00:00']);

    return $order->refresh();
}

test('web and API expose identical persisted shipment facts at every lifecycle milestone', function () {
    $this->travelTo('2026-10-08 09:00:00');
    $order = shipmentPresentationOrder();
    $customer = $order->user;
    $token = $customer->createToken('Phone')->plainTextToken;
    $this->actingAs($customer)->withToken($token);
    $processing = app(OrderProcessingService::class);
    $timeline = [['status' => 'awaiting_preparation', 'label' => 'Awaiting preparation', 'occurred_at' => '2026-10-08T08:00:00+00:00']];
    $eta = [
        'anchor_date' => null, 'timezone' => null, 'estimated_delivery_start' => null, 'estimated_delivery_end' => null,
        'notice' => 'Battlefront estimate from the start of preparation; arrival is not guaranteed.',
    ];
    $milestones = [
        'awaiting_preparation' => 'Awaiting preparation',
        'preparing' => 'Preparing for shipment',
        'ready_for_dispatch' => 'Ready for dispatch',
        'handed_to_lbc' => 'Handed to LBC',
        'in_transit' => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
    ];
    foreach ($milestones as $status => $label) {
        if ($status !== 'awaiting_preparation') {
            $processing->updateShipmentStatus($order, ShipmentStatus::from($status), $status === 'handed_to_lbc' ? 'SUPPLIED-REFERENCE' : null);
            $timeline[] = ['status' => $status, 'label' => $label, 'occurred_at' => '2026-10-08T09:00:00+00:00'];
            $eta = [
                ...$eta, 'anchor_date' => '2026-10-08', 'timezone' => 'UTC',
                'estimated_delivery_start' => '2026-10-10', 'estimated_delivery_end' => '2026-10-11',
            ];
        }
        $expected = [
            'carrier' => 'lbc', 'status' => ['value' => $status, 'label' => $label],
            'tracking_reference' => count($timeline) >= 4 ? 'SUPPLIED-REFERENCE' : null,
            'eta' => $eta, 'timeline' => $timeline,
            'notice' => 'Shipment status is manually maintained by Battlefront. This is not live LBC or GPS tracking.',
            'history_notice' => null,
        ];

        $web = $this->get(route('orders.show', $order))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.shipment', $expected)
                ->where('order.delivery_quote.destination', 'Bacolod City')
                ->where('order.delivery_fee', '250.00')
                ->missing('order.shipment_actions')
                ->missing('order.payment_proof_path'));
        $this->get('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.shipment', $expected)
            ->assertJsonPath('data.delivery_quote', $web->inertiaProps('order.delivery_quote'))
            ->assertJsonPath('data.fulfillment', $web->inertiaProps('order.fulfillment'))
            ->assertJsonMissingPath('data.shipment_actions')
            ->assertJsonMissingPath('data.payment_proof_path');
    }

    config(['battlefront.delivery.carrier' => 'other', 'battlefront.delivery.destinations' => []]);
    $this->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.shipment.carrier', 'lbc')
        ->assertJsonPath('data.shipment.eta.estimated_delivery_start', '2026-10-10')
        ->assertJsonPath('data.delivery_quote.delivery_fee', '250.00');
});

test('customers cannot read foreign shipment data on web or API', function (string $channel) {
    $order = shipmentPresentationOrder();
    $order->shipment->update(['tracking_reference' => 'PRIVATE-REFERENCE']);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken)
        ->get($channel === 'web' ? route('orders.show', $order) : '/api/v1/orders/'.$order->id)
        ->assertNotFound()->assertDontSee('PRIVATE-REFERENCE');
})->with(['web', 'api']);

test('pickup and legacy orders remain readable with no shipment presentation', function (bool $delivery) {
    $factory = Order::factory();
    $order = ($delivery ? $factory->delivery() : $factory)->create();
    $this->actingAs($order->user)->withToken($order->user->createToken('Phone')->plainTextToken);

    $this->get(route('orders.show', $order))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.shipment', null)->where('order.delivery_quote', null));
    $this->get('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.shipment', null)->assertJsonPath('data.total', $order->total_amount);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.show', $order))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.shipment', null)->where('order.shipment_actions', null));
})->with(['pickup' => [false], 'legacy delivery' => [true]]);

test('cancelled delivery presents a terminal status and retains recorded milestones on both channels', function () {
    $this->travelTo('2026-10-08 09:00:00');
    $order = shipmentPresentationOrder();
    $processing = app(OrderProcessingService::class);
    foreach ([ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit] as $status) {
        $processing->updateShipmentStatus($order, $status);
    }
    $this->travel(1)->hours();
    $processing->updateStatus($order, OrderStatus::Cancelled);
    $this->actingAs($order->user)->withToken($order->user->createToken('Phone')->plainTextToken);

    $web = $this->get(route('orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('order.shipment.status.value', 'cancelled')
        ->where('order.shipment.timeline.5', ['status' => 'cancelled', 'label' => 'Cancelled', 'occurred_at' => '2026-10-08T10:00:00+00:00']));
    $this->get('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.shipment', $web->inertiaProps('order.shipment'));
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.show', $order))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.shipment_actions', ['next_status' => null, 'can_update_reference' => false])
            ->where('order.allowed_status_transitions', []));
});

test('administrator shipment actions reflect order prerequisites and only the next milestone', function (OrderStatus $status, PaymentStatus $payment, ?array $next) {
    $order = shipmentPresentationOrder();
    $order->update(['status' => $status, 'payment_status' => $payment]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.show', $order))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.shipment_actions.next_status', $next)
            ->where('order.shipment_actions.can_update_reference', false)
            ->where('order.delivery_quote.destination', 'Bacolod City')
            ->where('order.shipment.carrier', 'lbc'));

    $this->assertDatabaseCount('sales', 0);
})->with([
    'pending order' => [OrderStatus::Pending, PaymentStatus::Verified, null],
    'pending payment' => [OrderStatus::Processing, PaymentStatus::Pending, null],
    'rejected payment' => [OrderStatus::Processing, PaymentStatus::Rejected, null],
    'eligible' => [OrderStatus::Processing, PaymentStatus::Verified, ['value' => 'preparing', 'label' => 'Preparing for shipment']],
    'old completed order' => [OrderStatus::Completed, PaymentStatus::Verified, null],
]);

test('old completed delivery is read only and does not invent delivery dates', function () {
    $order = shipmentPresentationOrder();
    $order->update(['status' => OrderStatus::Completed]);
    $this->actingAs($order->user)->withToken($order->user->createToken('Phone')->plainTextToken);

    $this->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.shipment.status.value', 'awaiting_preparation')
        ->assertJsonPath('data.shipment.history_notice', 'This order was completed before the shipment workflow was introduced. Only recorded milestones are shown.')
        ->assertJsonCount(1, 'data.shipment.timeline')
        ->assertJsonPath('data.shipment.eta.anchor_date', null);
    expect($order->refresh()->shipment->delivered_at)->toBeNull();
    $this->assertDatabaseCount('sales', 0);
});
