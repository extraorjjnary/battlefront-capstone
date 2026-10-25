<?php

use App\Actions\Order\PlaceCustomerOrder;
use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Jobs\RetryOrderNotifications;
use App\Listeners\PersistOrderNotifications;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('customer checkout notifies every administrator of the new order and initial wallet evidence', function (string $method) {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $administrators = User::factory()->administrator()->count(2)->create();
    $product = Inventory::factory()->create(['quantity' => 5])->product;
    $item = (new CartService)->add($customer, $product->id, 1);

    $order = app(PlaceCustomerOrder::class)->execute($customer, [
        'cart_item_ids' => [$item->id], 'recipient_name' => 'Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup', 'delivery_destination' => null, 'payment_method' => $method,
    ], $method === 'cash' ? null : UploadedFile::fake()->image('proof.png'));

    foreach ($administrators as $administrator) {
        expect($administrator->notifications()->pluck('data')->pluck('event')->sort()->values()->all())
            ->toBe($method === 'cash' ? ['order.placed'] : ['order.placed', 'payment.proof_submitted']);
        expect($administrator->notifications()->first()->data['order_id'])->toBe($order->id);
    }
    expect($customer->notifications()->count())->toBe(0);
    expect(Inventory::sole()->quantity)->toBe(4);
})->with(['cash', 'gcash', 'maya']);

test('failed checkout and an outer rollback create no notifications', function () {
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();
    $item = (new CartService)->add($customer, Inventory::factory()->create(['quantity' => 3])->product_id, 1);
    $input = [
        'cart_item_ids' => [$item->id], 'recipient_name' => 'Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup', 'delivery_destination' => null, 'payment_method' => 'cash',
    ];

    expect(fn () => DB::transaction(function () use ($customer, $input): void {
        app(PlaceCustomerOrder::class)->execute($customer, $input, null);
        throw new RuntimeException('Outer rollback');
    }))->toThrow(RuntimeException::class, 'Outer rollback');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('notifications', 0);
    expect(Inventory::sole()->quantity)->toBe(3);
});

test('manual payment decisions notify only the order owner and repeated requests cannot duplicate history', function (PaymentStatus $status) {
    Storage::fake('local');
    $order = Order::factory()->paidWithGCash()->create();
    Storage::disk('local')->put($order->payment_proof_path, 'proof');
    $other = User::factory()->customer()->create();
    User::factory()->administrator()->create();

    app(OrderProcessingService::class)->updatePaymentStatus($order, $status, PaymentRejectionReason::TransactionUnverified);

    expect($order->user->notifications()->sole()->data['event'])->toBe('payment.'.$status->value);
    expect($other->notifications()->count())->toBe(0);
    expect(fn () => app(OrderProcessingService::class)->updatePaymentStatus($order, $status))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 1);
})->with([PaymentStatus::Verified, PaymentStatus::Rejected]);

test('replacement proof notifies administrators once and later rejection remains a distinct event', function () {
    Storage::fake('local');
    $order = Order::factory()->paidWithGCash()->create();
    Storage::disk('local')->put($order->payment_proof_path, 'original');
    Storage::disk('local')->put('payment-proofs/replacement.png', 'replacement');
    $administrator = User::factory()->administrator()->create();

    app(OrderProcessingService::class)->updatePaymentStatus($order, PaymentStatus::Rejected, PaymentRejectionReason::TransactionUnverified);
    app(ResubmitPaymentProof::class)->execute($order->user, $order, 'payment-proofs/replacement.png');
    expect(fn () => app(ResubmitPaymentProof::class)->execute($order->user, $order, 'payment-proofs/replacement.png'))->toThrow(ValidationException::class);
    app(OrderProcessingService::class)->updatePaymentStatus($order, PaymentStatus::Rejected, PaymentRejectionReason::TransactionUnverified);

    expect($administrator->notifications()->sole()->data['event'])->toBe('payment.proof_submitted');
    expect($order->user->notifications()->count())->toBe(2);
    expect($order->user->notifications()->pluck('data')->pluck('event')->all())->toBe(['payment.rejected', 'payment.rejected']);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('shipment milestones create meaningful customer history while reference edits remain silent', function () {
    $order = Order::factory()->withDeliverySnapshot()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified]);
    Shipment::factory()->for($order)->withHandoffDetails($order->total_amount)->create();
    $service = app(OrderProcessingService::class);

    foreach ([ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
        $service->updateShipmentStatus($order, $status);
        expect(fn () => $service->updateShipmentStatus($order, $status))->toThrow(ValidationException::class);
    }
    $service->updateShipmentReference($order, 'REAL-REFERENCE');

    expect($order->user->notifications()->pluck('data')->pluck('event')->sort()->values()->all())->toBe([
        'shipment.delivered', 'shipment.handed_to_lbc', 'shipment.in_transit',
        'shipment.out_for_delivery', 'shipment.preparing', 'shipment.ready_for_dispatch',
    ]);
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
    $this->assertDatabaseCount('sales', 1);
});

test('order cancellation notifies once and restores purchased stock once', function () {
    $order = Order::factory()->withDeliverySnapshot()->create();
    Shipment::factory()->for($order)->create();
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'quantity' => 2]);

    app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled))->toThrow(ValidationException::class);

    expect($order->user->notifications()->sole()->data['event'])->toBe('order.cancelled');
    expect($inventory->refresh()->quantity)->toBe(5);
    expect($order->refresh()->shipment->status)->toBe(ShipmentStatus::Cancelled);
});

test('customer cancellation confirms to the owner and alerts all administrators through web and mobile', function (string $channel) {
    $order = Order::factory()->create();
    $customer = $order->user;
    $other = User::factory()->customer()->create();
    $administrators = User::factory()->administrator()->count(2)->create();
    $stock = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 2]);

    if ($channel === 'web') {
        $this->actingAs($customer)->post(route('orders.cancel.store', $order), ['cancellation_initiator' => 'administrator'])
            ->assertRedirect(route('orders.show', $order))
            ->assertInertiaFlash('toast.message', 'Order cancelled successfully.');
    } else {
        $this->withToken($customer->createToken('Phone')->plainTextToken)
            ->postJson(route('api.v1.orders.cancel.store', $order), ['cancellation_initiator' => 'administrator'])
            ->assertOk()->assertJsonPath('data.status.value', 'cancelled');
    }

    $confirmation = $customer->notifications()->sole();
    expect($confirmation->data)->toMatchArray([
        'event' => 'order.cancelled', 'audience' => 'customer', 'cancellation_initiator' => 'customer',
        'title' => 'Order cancellation confirmed',
        'body' => 'You cancelled order '.$order->reference.'. It remains in your order history.',
    ]);
    expect($other->notifications()->count())->toBe(0);
    expect($stock->refresh()->quantity)->toBe(5);
    $this->withToken($customer->createToken('History')->plainTextToken)->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonPath('data.0.event', 'order.cancelled')
        ->assertJsonPath('data.0.title', $confirmation->data['title'])
        ->assertJsonPath('data.0.body', $confirmation->data['body'])
        ->assertJsonPath('data.0.order.deep_link.order_id', $order->id);

    foreach ($administrators as $administrator) {
        expect($administrator->notifications()->sole()->data)->toMatchArray([
            'event' => 'order.cancelled', 'audience' => 'administrator', 'cancellation_initiator' => 'customer',
            'title' => 'Order cancelled by customer',
            'body' => 'The customer cancelled order '.$order->reference.'. Open the order to review its details.',
        ]);
        $this->actingAs($administrator)->get(route('administration.notifications.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'Order cancelled by customer')
                ->where('notifications.data.0.order.web_url', route('administration.orders.show', $order)));
    }
    $this->assertDatabaseCount('notifications', 3);
    expect(fn () => app(OrderProcessingService::class)->cancelForCustomer($customer, $order->id))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 3);
    expect($stock->refresh()->quantity)->toBe(5);
})->with(['web', 'mobile']);

test('administrator cancellation notifies the affected customer while preserving the success toast without self notifications', function () {
    $order = Order::factory()->create();
    $administrator = User::factory()->administrator()->create();
    $otherAdministrator = User::factory()->administrator()->create();
    $otherCustomer = User::factory()->customer()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled', 'cancellation_initiator' => 'customer'])
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertInertiaFlash('toast.message', 'Order status updated.');

    expect($order->user->notifications()->sole()->data)->toMatchArray([
        'event' => 'order.cancelled', 'audience' => 'customer', 'cancellation_initiator' => 'administrator',
        'title' => 'Order cancelled by Battlefront',
        'body' => 'Battlefront cancelled your order '.$order->reference.'. Open your order for details.',
    ]);
    expect($administrator->notifications()->count())->toBe(0);
    expect($otherAdministrator->notifications()->count())->toBe(0);
    expect($otherCustomer->notifications()->count())->toBe(0);
    $this->assertDatabaseCount('notifications', 1);
});

test('rolled back cancellations do not notify either audience', function (UserRole $initiator) {
    $order = Order::factory()->create();
    User::factory()->administrator()->count(2)->create();
    $stock = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 2]);
    $service = app(OrderProcessingService::class);

    expect(fn () => DB::transaction(function () use ($order, $initiator, $service): void {
        if ($initiator === UserRole::Customer) {
            $service->cancelForCustomer($order->user, $order->id);
        } else {
            $service->updateStatus($order, OrderStatus::Cancelled);
        }
        throw new RuntimeException('Cancellation rolled back.');
    }))->toThrow(RuntimeException::class, 'Cancellation rolled back.');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    expect($stock->refresh()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('notification_push_deliveries', 0);
})->with([UserRole::Customer, UserRole::Administrator]);

test('ineligible cancellations publish no event or notification for either audience', function (UserRole $initiator) {
    $order = Order::factory()->create($initiator === UserRole::Customer
        ? ['payment_status' => PaymentStatus::Verified]
        : ['status' => OrderStatus::Completed]);
    User::factory()->administrator()->count(2)->create();
    $stock = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 2]);
    Event::fake([OrderNotificationOccurred::class]);
    $service = app(OrderProcessingService::class);

    expect(fn () => $initiator === UserRole::Customer
        ? $service->cancelForCustomer($order->user, $order->id)
        : $service->updateStatus($order, OrderStatus::Cancelled))->toThrow(ValidationException::class);

    Event::assertNotDispatched(OrderNotificationOccurred::class);
    $this->assertDatabaseCount('notifications', 0);
    expect($stock->refresh()->quantity)->toBe(3);
})->with([UserRole::Customer, UserRole::Administrator]);

test('partial cancellation notification failure preserves the initiator on retry without duplicating either audience', function () {
    Bus::fake([RetryOrderNotifications::class]);
    $order = Order::factory()->create();
    $firstAdmin = User::factory()->administrator()->create();
    $secondAdmin = User::factory()->administrator()->create();
    Event::listen('eloquent.creating: '.DatabaseNotification::class, function (DatabaseNotification $notification) use ($secondAdmin): void {
        if ((int) $notification->notifiable_id === $secondAdmin->id) {
            throw new RuntimeException('Administrator notification unavailable.');
        }
    });

    try {
        app(OrderProcessingService::class)->cancelForCustomer($order->user, $order->id);
    } finally {
        Event::forget('eloquent.creating: '.DatabaseNotification::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    $confirmation = $order->user->notifications()->sole();
    $confirmation->markAsRead();
    $firstAdmin->notifications()->sole()->markAsRead();
    expect($secondAdmin->notifications()->count())->toBe(0);
    Bus::assertDispatchedTimes(RetryOrderNotifications::class, 1);
    $retry = unserialize(serialize(Bus::dispatched(RetryOrderNotifications::class)->sole()));
    expect($retry->event->cancellationInitiator)->toBe(UserRole::Customer);

    app()->call([$retry, 'handle']);
    app()->call([$retry, 'handle']);

    $this->assertDatabaseCount('notifications', 3);
    expect($confirmation->refresh()->read_at)->not->toBeNull();
    expect($firstAdmin->notifications()->sole()->read_at)->not->toBeNull();
    expect($secondAdmin->notifications()->sole()->data['cancellation_initiator'])->toBe('customer');
    expect($order->user->notifications()->sole()->id)->toBe($confirmation->id);
});

test('legacy cancellation events retain their customer-only audience and original messages', function () {
    $order = Order::factory()->create();
    $administrator = User::factory()->administrator()->create();
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'order.cancelled', $order->id, $order->user_id, $order->reference, now()->toIso8601String());

    app()->call([unserialize(serialize(new RetryOrderNotifications($event))), 'handle']);

    expect($event->audience())->toBe('customer');
    expect($order->user->notifications()->sole()->data)->toBe([
        'version' => 1, 'event' => 'order.cancelled', 'audience' => 'customer',
        'title' => 'Order cancelled',
        'body' => 'There is an update for order '.$order->reference.'. Open your order for details.',
        'order_id' => $order->id, 'order_reference' => $order->reference, 'occurred_at' => $event->occurredAt,
    ]);
    expect($administrator->notifications()->count())->toBe(0);
});

test('sale failure discards the delivered notification together with rolled back business changes', function () {
    $order = Order::factory()->withDeliverySnapshot()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::OutForDelivery]);
    Event::listen('eloquent.created: '.Sale::class, fn (): never => throw new RuntimeException('Sale failure'));

    try {
        expect(fn () => app(OrderProcessingService::class)->updateShipmentStatus($order, ShipmentStatus::Delivered))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }

    $this->assertDatabaseCount('notifications', 0);
    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment->status)->toBe(ShipmentStatus::OutForDelivery);
});

test('replaying a domain event preserves one notification and its existing read state', function () {
    $order = Order::factory()->create();
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'payment.verified', $order->id, $order->user_id, $order->reference, now()->toIso8601String());
    $listener = app(PersistOrderNotifications::class);
    $listener->handle($event);
    $notification = $order->user->notifications()->sole();
    $notification->markAsRead();

    $listener->handle($event);
    app()->call([new RetryOrderNotifications($event), 'handle']);

    $this->assertDatabaseCount('notifications', 1);
    expect($notification->refresh()->read_at)->not->toBeNull();
});

test('persistence failure queues a retry without failing committed checkout or deleting its proof', function () {
    Storage::fake('local');
    Bus::fake([RetryOrderNotifications::class]);
    $customer = User::factory()->customer()->create();
    User::factory()->administrator()->create();
    $item = (new CartService)->add($customer, Inventory::factory()->create(['quantity' => 3])->product_id, 1);
    Event::listen('eloquent.creating: '.DatabaseNotification::class, fn (): never => throw new RuntimeException('Forced persistence failure'));

    try {
        $order = app(PlaceCustomerOrder::class)->execute($customer, [
            'cart_item_ids' => [$item->id], 'recipient_name' => 'Customer', 'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup', 'delivery_destination' => null, 'payment_method' => 'gcash',
        ], UploadedFile::fake()->image('proof.png'));
    } finally {
        Event::forget('eloquent.creating: '.DatabaseNotification::class);
    }

    Storage::disk('local')->assertExists($order->payment_proof_path);
    expect(Inventory::sole()->quantity)->toBe(2);
    Bus::assertDispatchedTimes(RetryOrderNotifications::class, 2);
    foreach (Bus::dispatched(RetryOrderNotifications::class) as $job) {
        app()->call([$job, 'handle']);
    }
    $this->assertDatabaseCount('notifications', 2);
});
