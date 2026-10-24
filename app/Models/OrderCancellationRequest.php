<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property numeric-string|null $amount_received
 * @property Carbon|null $created_at
 * @property CarbonImmutable|null $reviewed_at
 */
class OrderCancellationRequest extends Model
{
    /** @return HasOne<CustomerRefund, $this> */
    public function refund(): HasOne
    {
        return $this->hasOne(CustomerRefund::class, 'cancellation_request_id');
    }

    protected function casts(): array
    {
        return ['amount_received' => 'decimal:2', 'reviewed_at' => 'immutable_datetime'];
    }
}
