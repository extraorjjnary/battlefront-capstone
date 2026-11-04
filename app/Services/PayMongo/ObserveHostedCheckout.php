<?php

namespace App\Services\PayMongo;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ObserveHostedCheckout
{
    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RecordPayMongoPayment $recordPayment,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    public function execute(PayMongoFlow $flow, bool $resumeFailure = false): HostedCheckoutObservation
    {
        return PaymentRecovery::locked($flow->order_id, function () use ($flow, $resumeFailure): HostedCheckoutObservation {
            $initialFailures = $flow->fresh()->recovery_failures;
            try {
                $result = $this->observe($flow->fresh(), $resumeFailure);
                $current = $flow->fresh();
                if ($current->status !== 'paid') {
                    $outcome = $current->status === 'closed' ? 'expired' : ($resumeFailure && ! $result->requiresFreshTestSession ? 'awaiting' : ($current->recovery_outcome ?? 'awaiting'));
                    app(PaymentRecovery::class)->observed($current, $outcome);
                }

                return $result;
            } catch (Throwable $exception) {
                if ($flow->fresh()->status !== 'paid' && $flow->fresh()->recovery_failures === $initialFailures) {
                    app(PaymentRecovery::class)->uncertain($flow->fresh(), $exception);
                }
                throw $exception;
            }
        });
    }

    private function observe(PayMongoFlow $flow, bool $resumeFailure): HostedCheckoutObservation
    {
        $session = $this->client->request()
            ->get('/v1/checkout_sessions/'.$flow->checkout_session_id)
            ->throw()
            ->json('data');

        if (! is_array($session)
            || data_get($session, 'id') !== $flow->checkout_session_id
            || data_get($session, 'attributes.livemode') !== false) {
            throw new RuntimeException('PayMongo checkout state could not be verified.');
        }

        $payments = data_get($session, 'attributes.payments');
        if (! is_array($payments)) {
            throw new RuntimeException('PayMongo payment attempts could not be verified.');
        }
        $intentId = data_get($session, 'attributes.payment_intent.id');
        if (data_get($session, 'attributes.status') === 'active' && is_string($intentId)) {
            $intent = $this->client->request()->get('/v1/payment_intents/'.$intentId)->throw()->json('data');
            if (! is_array($intent) || data_get($intent, 'id') !== $intentId
                || data_get($intent, 'attributes.livemode') !== false
                || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
                || data_get($intent, 'attributes.currency') !== $flow->currency
                || ! is_array(data_get($intent, 'attributes.payments', []))) {
                throw new RuntimeException('Payment intent state could not be verified.');
            }
            data_set($session, 'attributes.payment_intent', $intent);
            $payments = array_merge($payments, data_get($intent, 'attributes.payments', []));
        }
        $paid = collect($payments)
            ->first(static fn (mixed $payment): bool => is_array($payment)
                && data_get($payment, 'attributes.status') === 'paid');
        if (is_array($paid)) {
            $attributes = data_get($paid, 'attributes', []);
            $payment = [
                'id' => data_get($paid, 'id'),
                'status' => data_get($attributes, 'status'),
                'amount' => data_get($attributes, 'amount'),
                'net_amount' => data_get($attributes, 'net_amount'),
                'currency' => data_get($attributes, 'currency'),
                'method' => data_get($attributes, 'source.type'),
            ];
            if (is_string($payment['id']) && is_int($payment['amount']) && is_string($payment['currency'])) {
                $this->recordPayment->execute($flow, $payment, $this->notifications);
            }
            throw new RuntimeException('PayMongo confirms payment. Order status is being reconciled.');
        }

        $flow->update(['recovery_outcome' => 'awaiting']);
        $sessionStatus = data_get($session, 'attributes.status');
        $intentId = data_get($session, 'attributes.payment_intent.id');
        if (is_string($intentId)) {
            DB::transaction(function () use ($flow, $intentId): void {
                Order::query()->lockForUpdate()->findOrFail($flow->order_id);
                $lockedFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
                if (! $lockedFlow->attempts()->where('payment_intent_id', $intentId)->exists()) {
                    $lockedFlow->attempts()->create([
                        'payment_intent_id' => $intentId,
                        'attempt_number' => ((int) $lockedFlow->attempts()->max('attempt_number')) + 1,
                        'bank_code' => '', 'status' => 'observed',
                    ]);
                }
            });
            PayMongoFlow::query()->whereKey($flow->id)->where('status', '!=', 'paid')
                ->update(['payment_intent_id' => $intentId]);
        }
        if ($sessionStatus === 'active') {
            $intent = data_get($session, 'attributes.payment_intent');
            $intentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
            if (in_array($intentStatus, ['processing', 'succeeded'], true)
                || collect($payments)->contains(fn (mixed $payment): bool => ! in_array(data_get($payment, 'attributes.status'), ['failed'], true))) {
                app(PaymentRecovery::class)->uncertain($flow);
                throw new RuntimeException('Payment is processing or requires reconciliation.');
            }
            foreach ($payments as $payment) {
                $paymentId = data_get($payment, 'id');
                if (! is_string($paymentId)) {
                    app(PaymentRecovery::class)->uncertain($flow);
                    throw new RuntimeException('Payment failure could not be verified.');
                }
                $this->recordOutcome($flow, 'observe_hosted_failure', $paymentId);
                if ($resumeFailure) {
                    $this->recordOutcome($flow, 'resume_hosted_failure', $paymentId);
                }
            }
            $lastPaymentError = data_get($intent, 'attributes.last_payment_error');
            if ($intentStatus === 'awaiting_payment_method' && is_string($intentId)
                && is_array($lastPaymentError) && $lastPaymentError !== []) {
                $reference = 'intent-error:'.hash('sha256', json_encode([
                    $intentId, data_get($intent, 'attributes.updated_at'), $lastPaymentError,
                ], JSON_THROW_ON_ERROR));
                $this->recordOutcome($flow, 'observe_hosted_failure', $reference);
                if ($resumeFailure) {
                    $this->recordOutcome($flow, 'resume_hosted_failure', $reference);
                }
            }
            $qrExpiry = data_get($intent, 'attributes.next_action.code.expires_at');
            $qrExpiresAt = null;
            if (is_int($qrExpiry)) {
                $qrExpiresAt = Carbon::createFromTimestamp($qrExpiry);
            } elseif (is_string($qrExpiry) && preg_match('/\A\d{4}-\d{2}-\d{2}T/', $qrExpiry)) {
                $qrExpiresAt = Carbon::parse($qrExpiry);
            } elseif ($qrExpiry !== null) {
                throw new RuntimeException('QR expiry could not be verified.');
            }
            if ($flow->hosted_payment_method === 'qrph' && is_string($intentId)
                && $qrExpiresAt !== null && $qrExpiresAt->lessThanOrEqualTo(now())) {
                $reference = $intentId.':'.$qrExpiry;
                $this->recordOutcome($flow, 'observe_hosted_failure', $reference, 'expired');
                if ($resumeFailure) {
                    $this->recordOutcome($flow, 'resume_hosted_failure', $reference);
                }
            }
            $requiresFreshTestSession = $this->recordTestQrSourceOutcome($flow, $intent);
            $checkoutUrl = data_get($session, 'attributes.checkout_url');
            if (! is_string($checkoutUrl)
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
                || parse_url($checkoutUrl, PHP_URL_HOST) !== 'checkout.paymongo.com'
                || parse_url($checkoutUrl, PHP_URL_USER) !== null
                || parse_url($checkoutUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('PayMongo checkout URL could not be recovered.');
            }
            DB::transaction(function () use ($flow, $checkoutUrl): void {
                Order::query()->lockForUpdate()->findOrFail($flow->order_id);
                $currentFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
                if (! in_array($currentFlow->status, ['open', 'reconciliation_required'], true)) {
                    throw new RuntimeException('Payment state changed during reconciliation.');
                }
                $currentFlow->update(['status' => 'open', 'checkout_url' => $checkoutUrl]);
            });

            return new HostedCheckoutObservation($requiresFreshTestSession ? null : $checkoutUrl, $requiresFreshTestSession);
        }

        if ($sessionStatus === 'expired') {
            $intent = data_get($session, 'attributes.payment_intent');
            if ($intent !== null && data_get($intent, 'attributes.status') !== 'cancelled') {
                throw new RuntimeException('The previous payment intent requires reconciliation.');
            }
            foreach ($payments as $payment) {
                if (data_get($payment, 'attributes.status') !== 'failed') {
                    throw new RuntimeException('A previous payment still requires reconciliation.');
                }
            }
            DB::transaction(function () use ($flow): void {
                $order = Order::query()->lockForUpdate()->findOrFail($flow->order_id);
                $currentFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
                if ($order->payment_status !== PaymentStatus::Pending
                    || $order->status !== OrderStatus::Pending
                    || $currentFlow->status === 'paid'
                    || app(OrderProcessingService::class)->hasCancellationHold($order)) {
                    throw new RuntimeException('This order cannot accept another payment.');
                }
                $currentFlow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);
            });

            return new HostedCheckoutObservation(null);
        }

        throw new RuntimeException('PayMongo checkout state requires reconciliation.');
    }

    private function recordOutcome(PayMongoFlow $flow, string $kind, string $reference, string $outcome = 'failed'): void
    {
        if ($kind === 'observe_hosted_failure' && ! $flow->operations()->where('kind', 'resume_hosted_failure')->where('provider_reference', $reference)->exists()) {
            $flow->update(['recovery_outcome' => $outcome]);
        }
        $flow->operations()->firstOrCreate(['kind' => $kind, 'provider_reference' => $reference], [
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $reference),
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $intent */
    private function recordTestQrSourceOutcome(PayMongoFlow $flow, ?array $intent): bool
    {
        $testUrl = data_get($intent, 'attributes.next_action.code.test_url');
        if ($flow->mode !== 'test' || config('services.paymongo.mode') !== 'test'
            || $flow->hosted_payment_method !== 'qrph' || ! is_string($testUrl)) {
            return false;
        }
        if (parse_url($testUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($testUrl, PHP_URL_HOST) !== 'secure-authentication.paymongo.com'
            || parse_url($testUrl, PHP_URL_USER) !== null
            || parse_url($testUrl, PHP_URL_PASS) !== null) {
            throw new RuntimeException('QR test source could not be verified.');
        }
        parse_str((string) parse_url($testUrl, PHP_URL_QUERY), $parameters);
        $sourceId = $parameters['id'] ?? null;
        $codeId = $parameters['code_id'] ?? null;
        if (! is_string($sourceId) || ! preg_match('/\Asrc_[a-zA-Z0-9]+\z/', $sourceId)
            || ! is_string($codeId) || $codeId !== data_get($intent, 'attributes.next_action.code.id')) {
            throw new RuntimeException('QR test source does not match the current code.');
        }
        $source = $this->client->request()->get('/v1/sources/'.$sourceId)->throw()->json('data');
        if (! is_array($source) || data_get($source, 'id') !== $sourceId
            || data_get($source, 'attributes.livemode') !== false
            || data_get($source, 'attributes.type') !== 'qrph'
            || data_get($source, 'attributes.amount') !== (int) $flow->amount_minor
            || data_get($source, 'attributes.currency') !== $flow->currency) {
            throw new RuntimeException('QR test source state could not be verified.');
        }
        $status = data_get($source, 'attributes.status');
        if (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
            $reference = 'test-source:'.hash('sha256', $sourceId.':'.$codeId.':'.$status);
            $this->recordOutcome($flow, 'observe_hosted_failure', $reference, $status === 'expired' ? 'expired' : 'failed');
            $flow->update(['recovery_outcome' => $status === 'expired' ? 'expired' : 'failed']);

            return true;
        } elseif (! in_array($status, ['pending', 'chargeable'], true)) {
            throw new RuntimeException('QR test payment requires reconciliation.');
        }

        return false;
    }
}
