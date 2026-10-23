<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayMongoFlow extends Model
{
    protected $table = 'paymongo_flows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'merchant_cutoff_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(PayMongoOperation::class, 'paymongo_flow_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayMongoAttempt::class, 'paymongo_flow_id');
    }
}
