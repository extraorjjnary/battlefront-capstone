<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Jobs\SendExpoNotification;
use App\Models\NotificationPushDelivery;
use App\Models\PushDevice;
use App\Notifications\OrderUpdateNotification;
use App\Services\Notifications\NotificationFailureReporter;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use Throwable;

class PersistOrderNotifications
{
    public function __construct(private readonly PushDeviceService $devices, private readonly NotificationRecipients $recipients) {}

    public function handle(OrderNotificationOccurred $event): void
    {
        foreach ($event->audiences() as $audience) {
            $this->persistForAudience($event, $audience);
        }
    }

    private function persistForAudience(OrderNotificationOccurred $event, UserRole $audience): void
    {
        $recipients = $this->recipients->forEvent($event, $audience);

        foreach ($recipients as $recipient) {
            $notification = $recipient->notifications()->firstOrCreate(
                ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, $event->id.':'.$recipient->id)->toString()],
                ['type' => OrderUpdateNotification::class, 'data' => (new OrderUpdateNotification($event, $audience))->toDatabase($recipient), 'read_at' => null],
            );

            if ($recipient->role !== UserRole::Customer || ! config('services.expo.enabled')) {
                continue;
            }

            try {
                $devices = PushDevice::query()->with(['user', 'accessToken'])->whereBelongsTo($recipient)->where('is_active', true)->get();
                foreach ($devices as $device) {
                    if (! $this->devices->eligible($device)) {
                        $this->devices->deactivate($device, $device->registration_version);

                        continue;
                    }
                    $delivery = NotificationPushDelivery::query()->firstOrCreate([
                        'notification_id' => $notification->id, 'push_device_id' => $device->id,
                        'registration_version' => $device->registration_version,
                    ], ['status' => 'pending']);
                    if ($delivery->status === 'pending') {
                        Bus::dispatch(new SendExpoNotification($delivery->id));
                    }
                }
            } catch (Throwable) {
                NotificationFailureReporter::report('push_enqueue', $event->id);
            }
        }
    }
}
