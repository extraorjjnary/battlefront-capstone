<?php

namespace App\Models;

use Database\Factories\NotificationPushDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $notification_id
 * @property int $push_device_id
 * @property string $registration_version
 * @property string $status
 * @property string|null $ticket_id
 * @property Carbon|null $accepted_at
 * @property string|null $error_code
 * @property-read PushDevice $device
 * @property-read DatabaseNotification $notification
 */
#[Fillable(['notification_id', 'push_device_id', 'registration_version', 'status', 'ticket_id', 'accepted_at', 'error_code'])]
class NotificationPushDelivery extends Model
{
    /** @use HasFactory<NotificationPushDeliveryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<PushDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(PushDevice::class, 'push_device_id');
    }

    /** @return BelongsTo<DatabaseNotification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class);
    }
}
