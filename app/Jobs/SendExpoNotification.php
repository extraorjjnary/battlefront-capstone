<?php

namespace App\Jobs;

use App\Models\NotificationPushDelivery;
use App\Services\Notifications\ExpoPushAdapter;
use App\Services\Notifications\NotificationFailureReporter;
use App\Services\Notifications\NotificationHistory;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendExpoNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onConnection('database')->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExpoPushAdapter $expo, PushDeviceService $devices, NotificationHistory $history): void
    {
        if (! config('services.expo.enabled')) {
            return;
        }
        $delivery = DB::transaction(function (): ?NotificationPushDelivery {
            $delivery = NotificationPushDelivery::query()->with(['device.user', 'device.accessToken', 'notification'])
                ->whereKey($this->deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->status !== 'pending') {
                return null;
            }
            $delivery->update(['status' => 'sending']);

            return $delivery;
        });
        if ($delivery === null) {
            return;
        }

        $device = $delivery->device;
        $notification = $delivery->notification;
        if ($device->registration_version !== $delivery->registration_version || ! $devices->eligible($device)
            || (int) $notification->notifiable_id !== $device->user_id
            || $notification->notifiable_type !== $device->user->getMorphClass()
            || ($notification->data['audience'] ?? null) !== 'customer') {
            $delivery->update(['status' => 'skipped']);
            if ($device->registration_version === $delivery->registration_version && ! $devices->eligible($device)) {
                $devices->deactivate($device, $delivery->registration_version);
            }

            return;
        }
        $presented = $history->presentMany($device->user, collect([$notification]))->sole();
        if ($presented['order'] === null) {
            $delivery->update(['status' => 'skipped']);

            return;
        }

        try {
            $result = $expo->send((string) $device->expo_push_token, [
                'notification_id' => $notification->id, 'order_id' => $presented['order']['id'],
                'deep_link' => $presented['order']['deep_link'],
            ]);
        } catch (RuntimeException $exception) {
            $delivery->update(['status' => 'pending']);
            throw $exception;
        }

        if ($result['status'] === 'error') {
            $delivery->update(['status' => 'failed', 'error_code' => $result['error_code']]);
            if ($result['error_code'] === 'DeviceNotRegistered') {
                $devices->deactivate($device, $delivery->registration_version);
            }

            return;
        }
        $delivery->update(['status' => 'accepted', 'ticket_id' => $result['id'], 'accepted_at' => now()]);
        try {
            Bus::dispatch((new CheckExpoReceipt($delivery->id))->delay(now()->addMinutes(15)));
        } catch (Throwable) {
            NotificationFailureReporter::report('receipt_enqueue', $notification->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationPushDelivery::query()->whereKey($this->deliveryId)->where('status', 'pending')
            ->update(['status' => 'failed', 'error_code' => 'RetriesExhausted']);
        NotificationFailureReporter::report('push_exhausted', (string) $this->deliveryId);
    }
}
