<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property numeric-string $approved_amount
 * @property numeric-string $paid_amount
 * @property-read numeric-string $confirmed_amount
 */
class CustomerRefund extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('confirmed_amount', function (Builder $query): void {
            $query->select('customer_refunds.*')->selectSub(
                DB::table('customer_refund_payments as payments')
                    ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
                    ->whereColumn('payments.customer_refund_id', 'customer_refunds.id')
                    ->where('reviews.decision', 'verified')
                    ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
                    ->selectRaw('COALESCE(SUM(payments.amount), 0)'),
                'confirmed_amount',
            );
        });
    }

    protected function casts(): array
    {
        return ['approved_by' => 'integer', 'approved_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'confirmed_amount' => 'decimal:2'];
    }
}
