<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApprovalConcentrationAlert extends Notification
{
    /**
     * @param  array<int, array{action: string, subject_id: int, quantity: int|null, approved_amount: string|null}>  $decisions
     */
    public function __construct(
        public readonly string $reviewerName,
        public readonly string $requesterName,
        public readonly int $count,
        public readonly array $decisions = [],
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
            'decisions' => $this->decisions,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
