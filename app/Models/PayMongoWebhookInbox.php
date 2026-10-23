<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $verified_data */
class PayMongoWebhookInbox extends Model
{
    protected $table = 'paymongo_webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_data' => 'array'];
    }
}
