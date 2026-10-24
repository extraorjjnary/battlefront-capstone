<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Jobs\RetryOrderNotifications;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderNotificationPublisher
{
    /** @param array<string, int|bool|null> $context */
    public function afterCommit(Order $order, string $kind, ?UserRole $cancellationInitiator = null, array $context = []): void
    {
        $event = new OrderNotificationOccurred(
            (string) Str::uuid(), $kind, $order->id, $order->user_id,
            $order->reference, now()->toIso8601String(),
            $cancellationInitiator,
            $context,
        );

        $this->publishAfterCommit($event);
    }

    public function operationalAfterCommit(string $kind, int $subjectId, int $requesterId, int $actorId, ?int $reviewerId = null): void
    {
        $this->publishAfterCommit(new OrderNotificationOccurred(
            (string) Str::uuid(), $kind, null, null, null, now()->toIso8601String(),
            context: ['subject_id' => $subjectId, 'requester_id' => $requesterId, 'actor_id' => $actorId, 'reviewer_id' => $reviewerId],
        ));
    }

    private function publishAfterCommit(OrderNotificationOccurred $event): void
    {
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
