<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TeamAccessChanged extends Notification
{
    public function __construct(
        public readonly string $event,
        public readonly string $actorName,
        public readonly int $subjectId,
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
            'event' => $this->event,
            'title' => 'Team access changed',
            'body' => "{$this->actorName} changed {$this->event} for record #{$this->subjectId}.",
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
