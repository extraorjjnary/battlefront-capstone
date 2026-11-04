<?php

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('retired push device endpoints return 404', function (string $method) {
    $customer = User::factory()->customer()->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->{$method}('/api/v1/push-devices/'.Str::uuid())->assertNotFound();

    $this->assertDatabaseCount('push_devices', 0);
})->with(['getJson', 'putJson', 'deleteJson']);

test('both audiences receive synchronous history without queued push even when legacy device records exist', function () {
    $order = Order::factory()->create();
    $administrator = User::factory()->administrator()->create();
    $deviceId = DB::table('push_devices')->insertGetId([
        'user_id' => $order->user_id, 'device_id' => (string) Str::uuid(),
        'platform' => 'android', 'registration_version' => (string) Str::uuid(),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['queue.default' => 'database']);
    Queue::fake();

    app(OrderNotificationPublisher::class)->afterCommit($order, 'order.cancelled', UserRole::Customer);

    expect($order->user->notifications()->sole()->data)->toMatchArray([
        'event' => 'order.cancelled', 'audience' => 'customer',
    ]);
    expect($administrator->notifications()->sole()->data)->toMatchArray([
        'event' => 'order.cancelled', 'audience' => 'administrator',
    ]);
    $this->assertDatabaseCount('notifications', 2);
    $this->assertDatabaseCount('notification_push_deliveries', 0);
    $this->assertDatabaseHas('push_devices', ['id' => $deviceId, 'is_active' => true]);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
