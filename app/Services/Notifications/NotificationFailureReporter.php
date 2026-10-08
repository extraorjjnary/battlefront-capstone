<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationFailureReporter
{
    public static function report(string $stage, string $id): void
    {
        try {
            Log::warning('Notification delivery failed.', ['stage' => $stage, 'notification_event_id' => $id]);
        } catch (Throwable) {
            // Reporting cannot turn a completed business action into a failure.
        }
    }
}
