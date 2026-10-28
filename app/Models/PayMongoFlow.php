<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $merchant_cutoff_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $qr_expires_at
 * @property int $amount_minor
 * @property string $channel
 * @property string $status
 * @property string $currency
 * @property string|null $bank_code
 * @property string|null $checkout_session_id
 * @property string|null $checkout_url
 * @property string|null $payment_intent_id
 * @property string|null $payment_id
 * @property string|null $payment_method
 * @property string|null $qr_code_image_url
 * @property string|null $hosted_payment_method
 * @property-read Order|null $order
 */
class PayMongoFlow extends Model
{
    protected $table = 'paymongo_flows';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (PayMongoFlow $flow): void {
            if ($flow->active_slot === null && ! in_array($flow->status, ['closed', 'paid'], strict: true)) {
                $flow->active_slot = 'active';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'merchant_cutoff_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'qr_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PayMongoOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(PayMongoOperation::class, 'paymongo_flow_id');
    }

    /** @return HasMany<PayMongoAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PayMongoAttempt::class, 'paymongo_flow_id');
    }
}
