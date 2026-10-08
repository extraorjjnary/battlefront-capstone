<?php

use App\Actions\Order\PlaceCustomerOrder;
use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
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
    $order = Order::factory()->create();
    $other = User::factory()->customer()->create();
    User::factory()->administrator()->create();

    app(OrderProcessingService::class)->updatePaymentStatus($order, $status);

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
    Shipment::factory()->for($order)->create();
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
