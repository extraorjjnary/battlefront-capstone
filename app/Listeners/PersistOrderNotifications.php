<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Notifications\OrderUpdateNotification;
use App\Services\Notifications\NotificationRecipients;
use Ramsey\Uuid\Uuid;

class PersistOrderNotifications
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

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
            $recipient->notifications()->firstOrCreate(
                ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, $event->id.':'.$recipient->id)->toString()],
                ['type' => OrderUpdateNotification::class, 'data' => (new OrderUpdateNotification($event, $audience))->toDatabase($recipient), 'read_at' => null],
            );
        }
    }
}
