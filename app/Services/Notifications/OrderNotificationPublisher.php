<?php

namespace App\Services\Notifications;

use App\Events\OrderNotificationOccurred;
use App\Jobs\RetryOrderNotifications;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderNotificationPublisher
{
    public function afterCommit(Order $order, string $kind): void
    {
        $event = new OrderNotificationOccurred(
            (string) Str::uuid(), $kind, $order->id, $order->user_id,
            $order->reference, now()->toIso8601String(),
        );

        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable) {
                NotificationFailureReporter::report('persistence', $event->id);
                try {
                    Bus::dispatch(new RetryOrderNotifications($event));
                } catch (Throwable) {
                    NotificationFailureReporter::report('persistence_enqueue', $event->id);
                }
            }
        });
    }
}
