<?php

use App\Actions\Order\AssignOrderHandler;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

function orderHandlerActor(string $role): User
{
    return User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
}

/** @return array{Order, string, string, array<string, mixed>} */
function orderHandlerOperation(string $operation): array
{
    $factory = match ($operation) {
        'manual' => Order::factory()->delivery()->paidWithGCash()->state([
            'product_subtotal' => '100.00', 'total_amount' => '100.00',
            'delivery_fee' => '0.00', 'delivery_quote_metadata' => ['pricing_status' => 'not_configured'],
        ]),
        'shipment', 'reference', 'delivered' => Order::factory()->withDeliverySnapshot(),
        'cod' => Order::factory()->cashOnDelivery(),
        'pickup' => Order::factory(),
        default => Order::factory()->paidWithGCash(),
    };
    $order = $factory->create([
        'status' => in_array($operation, ['complete', 'shipment', 'reference', 'delivered', 'pickup', 'cod'], true) ? OrderStatus::Processing : OrderStatus::Pending,
        'payment_status' => in_array($operation, ['verify', 'reject', 'pickup', 'cod'], true) ? PaymentStatus::Pending : PaymentStatus::Verified,
        'handler_required' => false,
    ]);
    if (in_array($operation, ['shipment', 'reference', 'delivered', 'cod'], true)) {
        Shipment::factory()->for($order)->create(['status' => match ($operation) {
            'reference' => ShipmentStatus::HandedToLbc,
            'delivered' => ShipmentStatus::OutForDelivery,
            'cod' => ShipmentStatus::Delivered,
            default => ShipmentStatus::AwaitingPreparation,
        }]);
    }
    $stock = Inventory::factory()->create(['quantity' => 7]);
    OrderItem::factory()->for($order)->create(['product_id' => $stock->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);
    [$route, $method, $payload] = match ($operation) {
        'verify', 'reject' => ['payment-status.update', 'patch', ['payment_status' => $operation === 'verify' ? 'verified' : 'rejected', 'manual_verification_confirmed' => true, 'rejection_reason' => 'image_unclear']],
        'manual' => ['shipment.manual.store', 'post', ['carrier' => 'lbc', 'arrangement_confirmed' => true]],
        'shipment', 'delivered' => ['shipment.status.update', 'patch', ['status' => $operation === 'shipment' ? 'preparing' : 'delivered']],
        'reference' => ['shipment.reference.update', 'patch', ['tracking_reference' => 'CORRECTED-REFERENCE']],
        'pickup' => ['pickup-confirmation.store', 'post', ['payment_received_confirmed' => true, 'products_handed_over_confirmed' => true]],
        'cod' => ['cod-collection.store', 'post', ['cod_collection_confirmed' => true]],
        default => ['status.update', 'patch', ['status' => match ($operation) {
            'complete' => 'completed', 'cancel' => 'cancelled', default => 'processing',
        }]],
    };

    return [$order, 'administration.orders.'.$route, $method, $payload];
}

dataset('handler operations', ['verify', 'reject', 'process', 'cancel', 'complete', 'manual', 'shipment', 'reference', 'delivered', 'pickup', 'cod']);

test('processing requires the current handler for staff managers and owners', function (string $operation, string $role, string $assignment) {
    [$order, $route, $method, $payload] = orderHandlerOperation($operation);
    $actor = orderHandlerActor($role);
    if ($assignment === 'other handler') {
        $order->assigned_handler_id = orderHandlerActor('staff')->id;
        $order->save();
    }
    $before = $order->fresh()->getAttributes();
    $shipmentBefore = $order->shipment?->getAttributes();

    $this->actingAs($actor)->{$method}(route($route, $order), $payload + ['assigned_handler_id' => $actor->id, 'actor' => null])->assertForbidden();

    expect($order->fresh()->getAttributes())->toBe($before);
    expect($order->fresh()->shipment?->getAttributes())->toBe($shipmentBefore);
    expect(Inventory::sole()->quantity)->toBe(7);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with('handler operations')->with(['staff', 'manager', 'owner'])->with(['unassigned', 'other handler']);

test('the assigned handler can perform each eligible processing action', function (string $operation, string $role) {
    Storage::fake('local');
    [$order, $route, $method, $payload] = orderHandlerOperation($operation);
    $actor = orderHandlerActor($role);
    $order->assigned_handler_id = $actor->id;
    $order->save();
    if ($order->payment_proof_path !== null) {
        Storage::disk('local')->put($order->payment_proof_path, 'evidence');
    }

    $this->actingAs($actor)->{$method}(route($route, $order), $payload)->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('audit_logs')->where('subject_id', $order->id)->where('actor_id', $actor->id)->count())->toBeGreaterThan(0);
    expect(Inventory::sole()->quantity)->toBe($operation === 'cancel' ? 9 : 7);
    expect($order->fresh()->assigned_handler_id)->toBe($actor->id);
    if (in_array($operation, ['complete', 'delivered', 'pickup', 'cod'], true)) {
        expect($order->fresh()->status)->toBe(OrderStatus::Completed);
        expect($order->sale()->sole()->handler_id)->toBe($actor->id);
        $this->assertDatabaseCount('sales', 1);
    }
})->with('handler operations')->with(['staff', 'manager', 'owner']);

test('stale processing models cannot bypass a committed reassignment', function (string $operation) {
    [$order] = orderHandlerOperation($operation);
    $former = orderHandlerActor('staff');
    $current = orderHandlerActor('staff');
    $manager = orderHandlerActor('manager');
    app(AssignOrderHandler::class)->execute($order, $manager, $former->id);
    $stale = $order->fresh();
    app(AssignOrderHandler::class)->execute($order, $manager, $current->id);
    $processing = app(OrderProcessingService::class);
    $before = $order->fresh()->getAttributes();
    $attempt = fn () => match ($operation) {
        'verify', 'reject' => $processing->updatePaymentStatus($stale, PaymentStatus::Verified, actor: $former),
        'manual' => $processing->createManualShipment($stale, 'lbc', null, null, null, true, actor: $former),
        'shipment', 'delivered' => $processing->updateShipmentStatus($stale, ShipmentStatus::Preparing, actor: $former),
        'reference' => $processing->updateShipmentReference($stale, 'STALE', actor: $former),
        'pickup' => $processing->confirmPickup($stale, true, true, actor: $former),
        'cod' => $processing->confirmCodCollection($stale, true, actor: $former),
        default => $processing->updateStatus($stale, OrderStatus::Processing, actor: $former),
    };

    expect($attempt)->toThrow(HttpException::class);
    expect($order->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('audit_logs', 2);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with('handler operations');

test('all eligible roles must claim before processing and cannot claim someone elses order', function (string $role) {
    $actor = orderHandlerActor($role);
    [$order, $route, $method, $payload] = orderHandlerOperation('process');
    $other = orderHandlerActor('staff');

    $this->actingAs($actor)->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.can_process', false)->where('order.allowed_status_transitions', []));
    $this->{$method}(route($route, $order), $payload)->assertForbidden();
    $this->post(route('administration.orders.claim', $order))->assertSessionHasNoErrors();
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.can_process', true));
    $this->{$method}(route($route, $order), $payload)->assertSessionHasNoErrors();
    $this->actingAs($other)->post(route('administration.orders.claim', $order))->assertSessionHasErrors('handler');
    expect($order->fresh()->assigned_handler_id)->toBe($actor->id);
})->with(['staff', 'manager', 'owner']);

test('supervisors explicitly transfer responsibility before completing and attributing a sale', function (string $role, bool $assignSelf) {
    [$order, $route, $method, $payload] = orderHandlerOperation('complete');
    $supervisor = orderHandlerActor($role);
    $first = orderHandlerActor('staff');
    $final = $assignSelf ? $supervisor : orderHandlerActor('staff');
    app(AssignOrderHandler::class)->execute($order, $supervisor, $first->id);

    $this->actingAs($supervisor)->{$method}(route($route, $order), $payload)->assertForbidden();
    $this->patch(route('administration.orders.handler.update', $order), ['handler_id' => $final->id])->assertSessionHasNoErrors();
    $this->actingAs($first)->{$method}(route($route, $order), $payload)->assertForbidden();
    $this->actingAs($final)->{$method}(route($route, $order), $payload)->assertSessionHasNoErrors();
    $this->actingAs($supervisor)->patch(route('administration.orders.handler.update', $order), ['handler_id' => $first->id])->assertSessionHasErrors('handler');

    expect($order->sale()->sole()->handler_id)->toBe($final->id);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $final->id, 'action' => 'order.status.updated', 'subject_id' => $order->id]);
})->with(['manager', 'owner'])->with([true, false]);

test('staff cannot reassign and ineligible administrators cannot claim even through the action', function () {
    [$order] = orderHandlerOperation('process');
    $staff = orderHandlerActor('staff');
    $other = orderHandlerActor('staff');
    $this->actingAs($staff)->patch(route('administration.orders.handler.update', $order), ['handler_id' => $other->id])->assertForbidden();
    $it = orderHandlerActor('it');

    expect(fn () => app(AssignOrderHandler::class)->execute($order, $it, $it->id, claimOnly: true))->toThrow(HttpException::class);
    expect($order->fresh()->assigned_handler_id)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('completed shipment tracking corrections belong only to the final handler', function () {
    [$order, $route, $method, $payload] = orderHandlerOperation('reference');
    $handler = orderHandlerActor('staff');
    $order->assigned_handler_id = $handler->id;
    $order->status = OrderStatus::Completed;
    $order->save();
    $order->shipment()->update(['status' => ShipmentStatus::Delivered]);
    $owner = orderHandlerActor('owner');

    $this->actingAs($owner)->{$method}(route($route, $order), $payload)->assertForbidden();
    $this->actingAs($handler)->{$method}(route($route, $order), $payload)->assertSessionHasNoErrors();
    expect($order->fresh()->shipment->tracking_reference)->toBe('CORRECTED-REFERENCE');
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    $this->assertDatabaseCount('sales', 0);
});

test('non-handlers retain order and proof visibility without processing capabilities', function (string $role) {
    Storage::fake('local');
    [$order] = orderHandlerOperation('reference');
    $order->assigned_handler_id = orderHandlerActor('staff')->id;
    $order->save();
    Storage::disk('local')->put($order->payment_proof_path, 'evidence');

    $this->actingAs(orderHandlerActor($role))->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.can_process', false)
            ->where('order.assigned_handler_id', $order->assigned_handler_id)
            ->where('order.shipment_actions.next_status', null)
            ->where('order.shipment_actions.can_update_reference', false)
            ->where('order.allowed_status_transitions', [])
            ->where('order.payment.can_confirm_pickup', false)
            ->where('order.payment.can_confirm_cod_collection', false)
            ->where('order.can_create_manual_shipment', false));
    $this->get(route('administration.orders.payment-proof.show', $order))->assertOk();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['staff', 'manager', 'owner']);

test('assigned staff still need processing permission and cannot claim after it is revoked', function () {
    [$order, $route, $method, $payload] = orderHandlerOperation('process');
    $staff = orderHandlerActor('staff');
    $order->assigned_handler_id = $staff->id;
    $order->save();
    DB::table('administration_role_permissions')->where('administration_role_id', $staff->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.process')->value('id'))->delete();

    $this->actingAs($staff)->{$method}(route($route, $order), $payload)->assertForbidden();
    $this->post(route('administration.orders.claim', Order::factory()->create()))->assertForbidden();
    expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Processing, actor: $staff))->toThrow(HttpException::class);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('supervisors cannot assign an ineligible handler', function (string $eligibility) {
    $supervisor = orderHandlerActor('manager');
    $target = $eligibility === 'customer' ? User::factory()->customer()->create() : orderHandlerActor($eligibility === 'it' ? 'it' : 'staff');
    if ($eligibility === 'inactive') {
        $target->is_active = false;
        $target->save();
    }
    $order = Order::factory()->create();

    $this->actingAs($supervisor)->patch(route('administration.orders.handler.update', $order), ['handler_id' => $target->id])->assertSessionHasErrors('handler');
    expect($order->fresh()->assigned_handler_id)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['customer', 'it', 'inactive']);

test('terminal orders cannot be claimed or reassigned', function (string $status) {
    $owner = orderHandlerActor('owner');
    $order = Order::factory()->create(['status' => $status]);

    $this->actingAs($owner)->post(route('administration.orders.claim', $order))->assertSessionHasErrors('handler');
    $this->patch(route('administration.orders.handler.update', $order), ['handler_id' => $owner->id])->assertSessionHasErrors('handler');
    expect($order->fresh()->assigned_handler_id)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['completed', 'cancelled']);
