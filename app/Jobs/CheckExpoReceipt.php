<?php

namespace App\Jobs;

use App\Models\NotificationPushDelivery;
use App\Services\Notifications\ExpoPushAdapter;
use App\Services\Notifications\NotificationFailureReporter;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class CheckExpoReceipt implements ShouldQueue
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
        return [60, 300];
    }

    public function handle(ExpoPushAdapter $expo, PushDeviceService $devices): void
    {
        if (! config('services.expo.enabled')) {
            return;
        }
        $delivery = NotificationPushDelivery::query()->with('device')->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== 'accepted' || $delivery->ticket_id === null) {
            return;
        }

        if (($delivery->accepted_at ?? $delivery->created_at)->lte(now()->subHours(24))) {
            $delivery->update(['status' => 'receipt_unavailable', 'error_code' => 'ReceiptExpired']);

            return;
        }
        $result = $expo->receipt($delivery->ticket_id);
        if ($result['status'] === 'pending') {
            Bus::dispatch((new self($delivery->id))->delay(now()->addMinutes(5)));

            return;
        }

        $delivery->update([
            'status' => $result['status'] === 'ok' ? 'provider_received' : 'failed',
            'error_code' => $result['error_code'] ?? null,
        ]);
        if (($result['error_code'] ?? null) === 'DeviceNotRegistered') {
            $devices->deactivate($delivery->device, $delivery->registration_version);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationFailureReporter::report('receipt_exhausted', (string) $this->deliveryId);
    }
}
