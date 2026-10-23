<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayMongoWebhookInbox extends Model
{
    protected $table = 'paymongo_webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_data' => 'array'];
    }
}
