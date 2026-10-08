<?php

namespace Database\Factories;

use App\Events\OrderNotificationOccurred;
use App\Models\NotificationPushDelivery;
use App\Models\Order;
use App\Models\PushDevice;
use App\Notifications\OrderUpdateNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/** @extends Factory<NotificationPushDelivery> */
class NotificationPushDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'push_device_id' => PushDevice::factory(),
            'registration_version' => fn (array $attributes): string => PushDevice::query()->whereKey($attributes['push_device_id'])->firstOrFail()->registration_version,
            'notification_id' => function (array $attributes): string {
                $device = PushDevice::query()->whereKey($attributes['push_device_id'])->firstOrFail();
                $order = Order::factory()->for($device->user)->create();
                $event = new OrderNotificationOccurred((string) Str::uuid(), 'payment.verified', $order->id, $device->user_id, $order->reference, now()->toIso8601String());
                $notification = new OrderUpdateNotification($event);

                return DatabaseNotification::create([
                    'id' => (string) Str::uuid(), 'type' => OrderUpdateNotification::class,
                    'notifiable_type' => $device->user->getMorphClass(), 'notifiable_id' => $device->user_id,
                    'data' => $notification->toDatabase($device->user), 'read_at' => null,
                ])->id;
            },
            'status' => 'pending',
            'accepted_at' => fn (array $attributes) => $attributes['status'] === 'accepted' ? now() : null,
        ];
    }
}
