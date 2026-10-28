<?php

namespace App\Services\PayMongo;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoAttempt;
use App\Models\PayMongoFlow;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Support\Facades\DB;

class RecordPayMongoPayment
{
    /** @param array{id: string, status: string, amount: int, net_amount: int|null, currency: string, method: string|null} $payment */
    public function execute(PayMongoFlow $flow, array $payment, OrderNotificationPublisher $notifications): bool
    {
        return DB::transaction(function () use ($flow, $payment, $notifications): bool {
            $order = Order::query()->lockForUpdate()->findOrFail($flow->order_id);
            $lockedFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);

            if ($payment['status'] !== 'paid'
                || $payment['currency'] !== $lockedFlow->currency
                || $payment['amount'] !== (int) $lockedFlow->amount_minor
                || ($lockedFlow->channel === 'direct_qrph' && $payment['method'] !== 'qrph')
                || ($lockedFlow->channel === 'hosted_checkout'
                    && is_string($lockedFlow->hosted_payment_method ?? $order->hosted_payment_method)
                    && $payment['method'] !== ($lockedFlow->hosted_payment_method ?? $order->hosted_payment_method))
                || $order->status->value === 'cancelled'
                || ($lockedFlow->payment_id !== null && $lockedFlow->payment_id !== $payment['id'])) {
                $lockedFlow->update(['status' => 'reconciliation_required']);

                return false;
            }

            $duplicatePayment = PayMongoFlow::query()
                ->where('payment_id', $payment['id'])
                ->where('id', '<>', $lockedFlow->id)
                ->exists()
                || PayMongoAttempt::query()
                    ->where('payment_id', $payment['id'])
                    ->where('paymongo_flow_id', '<>', $lockedFlow->id)
                    ->exists();

            if ($duplicatePayment) {
                $lockedFlow->update(['status' => 'reconciliation_required']);

                return false;
            }

            $lockedFlow->update([
                'payment_id' => $payment['id'],
                'payment_method' => $payment['method'],
                'status' => 'paid',
                'active_slot' => null,
                'paid_at' => $lockedFlow->paid_at ?? now(),
            ]);
            $attempt = PayMongoAttempt::query()
                ->where('paymongo_flow_id', $lockedFlow->id)
                ->where('payment_intent_id', $lockedFlow->payment_intent_id)
                ->where(function ($query) use ($payment): void {
                    $query->where('payment_id', $payment['id'])->orWhereNull('payment_id');
                })
                ->latest('id')
                ->first();
            $attempt?->update(['payment_id' => $payment['id'], 'status' => 'succeeded', 'completed_at' => now()]);

            if ($order->payment_status !== PaymentStatus::Verified) {
                $order->payment_status = PaymentStatus::Verified;
                $order->save();
                $notifications->afterCommit($order, 'payment.verified');
            }

            return true;
        }, attempts: 3);
    }
}
