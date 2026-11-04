<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoOperation;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CloseHostedCheckout
{
    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RecordPayMongoPayment $recordPayment,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    /**
     * Close an unpaid Hosted Checkout and confirm terminal provider state.
     *
     * A false result means the order must remain pending with inventory reserved.
     */
    public function execute(int $orderId, ?int $flowId = null): bool
    {
        return PaymentRecovery::locked($orderId, fn (): bool => $this->close($orderId, $flowId));
    }

    private function close(int $orderId, ?int $flowId): bool
    {
        $flow = DB::transaction(function () use ($orderId, $flowId): ?PayMongoFlow {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $flowQuery = PayMongoFlow::query()->where('order_id', $order->id);
            if ($flowId !== null) {
                $flowQuery->whereKey($flowId);
            } else {
                $flowQuery->where('active_slot', 'active');
            }
            $flow = $flowQuery->lockForUpdate()->first();

            if ($flow === null || $flow->status === 'closed') {
                return $flow;
            }

            if ($flow->status === 'failed' && $flow->checkout_session_id === null && $flow->payment_intent_id === null) {
                $createOperation = $flow->operations()->whereIn('kind', ['create_checkout_session', 'create_payment_intent', 'create_qr_payment_intent'])
                    ->where('status', 'failed')->exists();
                if ($createOperation) {
                    $flow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);

                    return $flow;
                }
            }

            if ((! in_array($flow->channel, ['hosted_checkout', 'direct_online_banking', 'direct_qrph'], strict: true))
                || ($flow->channel === 'hosted_checkout' && $flow->checkout_session_id === null)
                || (in_array($flow->channel, ['direct_online_banking', 'direct_qrph'], strict: true) && $flow->payment_intent_id === null)
                || $order->payment_status->value === 'verified' || $order->status->value !== 'pending') {
                return null;
            }

            $staleRetry = $flow->status === 'retrying'
                && $flow->merchant_cutoff_at->isPast()
                && $flow->operations()->where('kind', 'retry_bank_payment')->where('status', 'dispatched')->where('dispatched_at', '<=', now()->subMinutes(2))->exists();

            if (! $staleRetry && ! in_array($flow->status, ['closing', 'reconciliation_required', 'open', 'awaiting_payment_method', 'failed'], strict: true)) {
                return null;
            }

            $flow->update(['status' => 'closing']);

            return $flow;
        }, attempts: 3);

        if ($flow === null) {
            return false;
        }

        if ($flow->status === 'closed') {
            return ! Order::query()->whereKey($orderId)->where('payment_status', 'verified')->exists();
        }

        if ($flow->channel === 'direct_online_banking') {
            return $this->closeDirectOnlineBanking($flow);
        }

        if ($flow->channel === 'direct_qrph') {
            return $this->closeDirectQrPh($flow);
        }

        try {
            $session = $this->retrieve($flow->checkout_session_id);
            if ($this->applyPaidPayment($flow, $session)) {
                return false;
            }

            if (! $this->hasOnlySafeToExpireAttempts($session)) {
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            if (data_get($session, 'attributes.status') !== 'expired') {
                $operation = $flow->operations()->create([
                    'operation_id' => (string) Str::uuid(),
                    'kind' => 'expire_checkout_session',
                    'idempotency_key' => (string) Str::uuid(),
                    'request_fingerprint' => hash('sha256', $flow->checkout_session_id),
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                ]);

                try {
                    $this->client->request()
                        ->post('/v1/checkout_sessions/'.$flow->checkout_session_id.'/expire')
                        ->throw();
                    $operation->update(['status' => 'completed', 'completed_at' => now(), 'provider_reference' => $flow->checkout_session_id]);
                } catch (Throwable $exception) {
                    $operation->update(['status' => 'unknown', 'next_check_at' => now()->addMinute()]);
                }

                $session = $this->retrieve($flow->checkout_session_id);
                if ($this->applyPaidPayment($flow, $session)) {
                    return false;
                }
            }

            if (data_get($session, 'attributes.status') !== 'expired' || ! $this->hasOnlySafeToExpireAttempts($session)) {
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            PayMongoOperation::query()
                ->where('paymongo_flow_id', $flow->id)
                ->where('kind', 'expire_checkout_session')
                ->whereIn('status', ['dispatched', 'unknown'])
                ->update(['status' => 'completed', 'provider_reference' => $flow->checkout_session_id, 'completed_at' => now()]);

            return DB::transaction(function () use ($flow): bool {
                $order = Order::query()->lockForUpdate()->findOrFail($flow->order_id);
                $lockedFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);

                if ($order->payment_status->value === 'verified' || $order->status->value !== 'pending') {
                    app(PaymentRecovery::class)->uncertain($lockedFlow);

                    return false;
                }

                $lockedFlow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            app(PaymentRecovery::class)->uncertain($flow);

            return false;
        }
    }

    private function closeDirectOnlineBanking(PayMongoFlow $flow): bool
    {
        try {
            $intent = $this->retrievePaymentIntent((string) $flow->payment_intent_id);
            if ($this->applyPaidIntentPayment($flow, $intent)) {
                return false;
            }

            $status = data_get($intent, 'attributes.status');
            if ($status === 'cancelled') {
                return $this->markClosed($flow);
            }

            if (! in_array($status, ['awaiting_payment_method', 'awaiting_next_action'], strict: true)) {
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            $operation = $flow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => 'cancel_payment_intent',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', (string) $flow->payment_intent_id),
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);

            try {
                $this->client->ordersApiRequest()
                    ->post('/v1/payment_intents/'.$flow->payment_intent_id.'/cancel')
                    ->throw();
            } catch (Throwable) {
                // Re-read below: a payment or another terminal transition may have won.
            }

            $intent = $this->retrievePaymentIntent((string) $flow->payment_intent_id);
            if ($this->applyPaidIntentPayment($flow, $intent)) {
                return false;
            }

            if (data_get($intent, 'attributes.status') !== 'cancelled') {
                $operation->update(['status' => 'unknown', 'next_check_at' => now()->addMinute()]);
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            $operation->update([
                'status' => 'completed',
                'provider_reference' => $flow->payment_intent_id,
                'completed_at' => now(),
            ]);

            return $this->markClosed($flow);
        } catch (Throwable) {
            app(PaymentRecovery::class)->uncertain($flow);

            return false;
        }
    }

    private function closeDirectQrPh(PayMongoFlow $flow): bool
    {
        try {
            $intent = $this->client->request()
                ->get('/v1/payment_intents/'.$flow->payment_intent_id)
                ->throw()
                ->json('data');
            if (! is_array($intent)
                || data_get($intent, 'id') !== $flow->payment_intent_id
                || data_get($intent, 'attributes.livemode') !== false
                || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
                || data_get($intent, 'attributes.currency') !== $flow->currency) {
                throw new RuntimeException('PayMongo QR Ph intent could not be verified.');
            }

            if ($this->applyPaidIntentPayment($flow, $intent)) {
                return false;
            }

            if (data_get($intent, 'attributes.status') === 'cancelled') {
                return $this->markClosed($flow);
            }

            if (! in_array(data_get($intent, 'attributes.status'), ['awaiting_payment_method', 'awaiting_next_action'], strict: true)) {
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            $operation = $flow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => 'cancel_qr_payment_intent',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', (string) $flow->payment_intent_id),
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);

            try {
                $this->client->request()
                    ->post('/v1/payment_intents/'.$flow->payment_intent_id.'/cancel')
                    ->throw();
            } catch (Throwable) {
                // Re-read below; a payment or terminal transition may have won.
            }

            $intent = $this->client->request()
                ->get('/v1/payment_intents/'.$flow->payment_intent_id)
                ->throw()
                ->json('data');
            if ($this->applyPaidIntentPayment($flow, $intent)) {
                return false;
            }
            if (data_get($intent, 'attributes.status') !== 'cancelled') {
                $operation->update(['status' => 'unknown', 'next_check_at' => now()->addMinute()]);
                app(PaymentRecovery::class)->uncertain($flow);

                return false;
            }

            $operation->update([
                'status' => 'completed',
                'provider_reference' => $flow->payment_intent_id,
                'completed_at' => now(),
            ]);

            return $this->markClosed($flow);
        } catch (Throwable) {
            app(PaymentRecovery::class)->uncertain($flow);

            return false;
        }
    }

    private function markClosed(PayMongoFlow $flow): bool
    {
        return DB::transaction(function () use ($flow): bool {
            $order = Order::query()->lockForUpdate()->findOrFail($flow->order_id);
            $lockedFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);

            if ($order->payment_status->value === 'verified' || $order->status->value !== 'pending') {
                app(PaymentRecovery::class)->uncertain($lockedFlow);

                return false;
            }

            $lockedFlow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);
            $attempt = $lockedFlow->attempts()->where('payment_intent_id', $lockedFlow->payment_intent_id)->latest('id')->first();
            $attempt?->update(['status' => 'cancelled', 'completed_at' => now()]);

            return true;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function retrievePaymentIntent(string $intentId): array
    {
        $intent = $this->client->ordersApiRequest()
            ->get('/v1/payment_intents/'.$intentId)
            ->throw()
            ->json('data');

        if (! is_array($intent) || data_get($intent, 'id') !== $intentId || data_get($intent, 'attributes.livemode') !== false) {
            throw new RuntimeException('PayMongo payment intent could not be verified.');
        }

        return $intent;
    }

    /** @param array<string, mixed> $intent */
    private function applyPaidIntentPayment(PayMongoFlow $flow, array $intent): bool
    {
        $payments = data_get($intent, 'attributes.payments', []);
        $payments = is_array($payments) ? $payments : [];
        $paid = collect($payments)
            ->first(static fn (mixed $payment): bool => is_array($payment)
                && data_get($payment, 'attributes.status') === 'paid');

        if (! is_array($paid)) {
            return false;
        }

        $attributes = data_get($paid, 'attributes', []);
        $payment = [
            'id' => data_get($paid, 'id'),
            'status' => data_get($attributes, 'status'),
            'amount' => data_get($attributes, 'amount'),
            'net_amount' => data_get($attributes, 'net_amount'),
            'currency' => data_get($attributes, 'currency'),
            'method' => data_get($attributes, 'source.type'),
        ];

        if (! is_string($payment['id']) || ! is_int($payment['amount']) || ! is_string($payment['currency'])) {
            app(PaymentRecovery::class)->uncertain($flow);

            return true;
        }

        $this->recordPayment->execute($flow, $payment, $this->notifications);

        return true;
    }

    /** @return array<string, mixed> */
    private function retrieve(string $sessionId): array
    {
        $session = $this->client->request()
            ->get('/v1/checkout_sessions/'.$sessionId)
            ->throw()
            ->json('data');

        if (! is_array($session) || data_get($session, 'id') !== $sessionId || data_get($session, 'attributes.livemode') !== false) {
            throw new RuntimeException('PayMongo session could not be verified.');
        }

        $intentId = data_get($session, 'attributes.payment_intent.id');
        if (is_string($intentId)) {
            $intent = $this->client->request()->get('/v1/payment_intents/'.$intentId)->throw()->json('data');
            $flow = PayMongoFlow::query()->where('checkout_session_id', $sessionId)->firstOrFail();
            if (! is_array($intent) || data_get($intent, 'id') !== $intentId
                || data_get($intent, 'attributes.livemode') !== false
                || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
                || data_get($intent, 'attributes.currency') !== $flow->currency
                || ! is_array(data_get($intent, 'attributes.payments'))) {
                throw new RuntimeException('PayMongo payment intent could not be verified.');
            }
            data_set($session, 'attributes.payment_intent', $intent);
            $payments = data_get($session, 'attributes.payments');
            if (! is_array($payments)) {
                throw new RuntimeException('PayMongo payments could not be verified.');
            }
            data_set($session, 'attributes.payments', array_merge($payments, data_get($intent, 'attributes.payments')));
        }

        return $session;
    }

    /** @param array<string, mixed>|null $session */
    private function applyPaidPayment(PayMongoFlow $flow, ?array $session): bool
    {
        $payments = data_get($session, 'attributes.payments', []);
        $payments = is_array($payments) ? $payments : [];
        $paid = collect($payments)
            ->first(static fn (mixed $payment): bool => is_array($payment)
                && data_get($payment, 'attributes.status') === 'paid');

        if (! is_array($paid)) {
            return false;
        }

        $attributes = data_get($paid, 'attributes', []);
        $payment = [
            'id' => data_get($paid, 'id'),
            'status' => data_get($attributes, 'status'),
            'amount' => data_get($attributes, 'amount'),
            'net_amount' => data_get($attributes, 'net_amount'),
            'currency' => data_get($attributes, 'currency'),
            'method' => data_get($attributes, 'source.type'),
        ];

        if (! is_string($payment['id']) || ! is_int($payment['amount']) || ! is_string($payment['currency'])) {
            app(PaymentRecovery::class)->uncertain($flow);

            return true;
        }

        $recorded = $this->recordPayment->execute($flow, $payment, $this->notifications);

        return true;
    }

    /** @param array<string, mixed>|null $session */
    private function hasOnlySafeToExpireAttempts(?array $session): bool
    {
        $payments = data_get($session, 'attributes.payments');
        if (! is_array($payments)) {
            return false;
        }
        $intent = data_get($session, 'attributes.payment_intent');
        if ($intent !== null && (! is_array($intent)
            || ! in_array(data_get($intent, 'attributes.status'), ['awaiting_payment_method', 'awaiting_next_action', 'cancelled'], true))) {
            return false;
        }
        foreach ($payments as $payment) {
            $status = data_get($payment, 'attributes.status');

            if ($status !== 'failed') {
                return false;
            }
        }

        return true;
    }
}
