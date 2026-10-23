<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoAttempt;
use App\Models\PayMongoFlow;
use App\Models\PayMongoOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RetryDirectOnlineBanking
{
    public function __construct(private readonly PayMongoClient $client) {}

    public function execute(Order $order): string
    {
        [$flow, $previousAttempt] = DB::transaction(function () use ($order): array {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $flow = PayMongoFlow::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $previousAttempt = $flow->attempts()->latest('attempt_number')->firstOrFail();

            if ($flow->channel !== 'direct_online_banking'
                || $flow->status !== 'awaiting_payment_method'
                || $lockedOrder->status->value !== 'pending'
                || $lockedOrder->payment_status->value !== 'pending'
                || $flow->merchant_cutoff_at->isPast()) {
                throw new RuntimeException('This bank payment cannot be retried.');
            }

            $flow->update(['status' => 'retrying', 'checkout_url' => null]);
            $flow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => 'retry_bank_payment',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', (string) $flow->id),
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);

            return [$flow, $previousAttempt];
        }, attempts: 3);

        try {
            $intent = $this->client->ordersApiRequest()
                ->get('/v1/payment_intents/'.$previousAttempt->payment_intent_id)
                ->throw()
                ->json('data');

            if (! is_array($intent)
                || data_get($intent, 'attributes.livemode') !== false
                || data_get($intent, 'attributes.status') !== 'awaiting_payment_method'
                || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
                || data_get($intent, 'attributes.currency') !== $flow->currency
                || ! is_string(data_get($intent, 'attributes.client_key'))) {
                throw new RuntimeException('PayMongo has not confirmed this bank payment can be retried.');
            }

            $bank = (string) $previousAttempt->bank_code;
            $methodType = in_array($bank, ['bpi', 'ubp'], strict: true) ? 'dob' : 'brankas';
            $methodAttributes = ['type' => $methodType, 'details' => ['bank_code' => $bank]];
            $methodOperation = $this->persistOperation($flow, 'retry_create_payment_method', $methodAttributes);
            $method = $this->client->ordersApiPublicRequest()
                ->withHeaders(['Idempotency-Key' => $methodOperation->idempotency_key])
                ->post('/v1/payment_methods', ['data' => ['attributes' => $methodAttributes]])
                ->throw()
                ->json('data');
            $methodId = data_get($method, 'id');
            if (! is_string($methodId)) {
                throw new RuntimeException('PayMongo returned an invalid bank payment method.');
            }
            $methodOperation->update(['status' => 'completed', 'provider_reference' => $methodId, 'completed_at' => now()]);

            $attempt = DB::transaction(function () use ($flow, $previousAttempt, $methodId, $bank): PayMongoAttempt {
                $nextAttempt = $flow->attempts()->create([
                    'payment_intent_id' => $previousAttempt->payment_intent_id,
                    'attempt_number' => $flow->attempts()->max('attempt_number') + 1,
                    'payment_method_id' => $methodId,
                    'bank_code' => $bank,
                    'status' => 'attaching',
                ]);
                $flow->update(['status' => 'retrying', 'checkout_url' => null]);

                return $nextAttempt;
            });

            $attachAttributes = [
                'payment_method' => $methodId,
                'client_key' => data_get($intent, 'attributes.client_key'),
                'return_url' => route('orders.show', ['order' => $order, 'paymongo_return' => 1]),
            ];
            $attachOperation = $this->persistOperation($flow, 'retry_attach_payment_method', $attachAttributes);
            $attachedIntent = $this->client->ordersApiPublicRequest()
                ->withHeaders(['Idempotency-Key' => $attachOperation->idempotency_key])
                ->post('/v1/payment_intents/'.$previousAttempt->payment_intent_id.'/attach', ['data' => ['attributes' => $attachAttributes]])
                ->throw()
                ->json('data');
            $redirectUrl = data_get($attachedIntent, 'attributes.next_action.redirect.url');

            if (! is_string($redirectUrl)
                || parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https'
                || ! is_string(parse_url($redirectUrl, PHP_URL_HOST))
                || parse_url($redirectUrl, PHP_URL_USER) !== null
                || parse_url($redirectUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('PayMongo returned an invalid bank redirect.');
            }

            DB::transaction(function () use ($flow, $attempt, $redirectUrl, $attachedIntent, $attachOperation): void {
                $paymentId = data_get($attachedIntent, 'attributes.payments.0.id');
                $flow->update(['status' => 'open', 'checkout_url' => $redirectUrl]);
                $attempt->update([
                    'payment_id' => is_string($paymentId) ? $paymentId : null,
                    'status' => data_get($attachedIntent, 'attributes.status', 'awaiting_next_action'),
                    'redirect_url' => $redirectUrl,
                ]);
                $attachOperation->update(['status' => 'completed', 'provider_reference' => $attempt->payment_intent_id, 'completed_at' => now()]);
            });

            return $redirectUrl;
        } catch (Throwable $exception) {
            $flow->update(['status' => 'reconciliation_required']);

            throw new RuntimeException('Bank retry is pending reconciliation. Your order remains saved and inventory stays reserved.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function persistOperation(PayMongoFlow $flow, string $kind, array $attributes): PayMongoOperation
    {
        return $flow->operations()->create([
            'operation_id' => (string) Str::uuid(),
            'kind' => $kind,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);
    }
}
