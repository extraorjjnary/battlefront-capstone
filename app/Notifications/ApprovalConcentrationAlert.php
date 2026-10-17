<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApprovalConcentrationAlert extends Notification
{
    public function __construct(
        public readonly string $reviewerName,
        public readonly string $requesterName,
        public readonly int $count,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'audience' => 'administrator',
            'event' => 'approval.concentration_alert',
            'title' => 'Repeated approvals need review',
            'body' => "{$this->reviewerName} approved {$this->count} requests from {$this->requesterName} within 24 hours.",
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
