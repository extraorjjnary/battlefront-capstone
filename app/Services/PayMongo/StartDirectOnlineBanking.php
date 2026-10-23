<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoAttempt;
use App\Models\PayMongoFlow;
use App\Models\PayMongoOperation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StartDirectOnlineBanking
{
    /** @var array<string, array{label: string, type: string, minimum: int, maximum: int}> */
    private const BANKS = [
        'bpi' => ['label' => 'BPI', 'type' => 'dob', 'minimum' => 100, 'maximum' => 5_000_000],
        'ubp' => ['label' => 'UnionBank', 'type' => 'dob', 'minimum' => 100, 'maximum' => 10_000_000],
        'landbank' => ['label' => 'LandBank', 'type' => 'brankas', 'minimum' => 100, 'maximum' => 5_000_000],
    ];

    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RetryDirectOnlineBanking $retryDirectOnlineBanking,
    ) {}

    /**
     * @param  array{payment_channel: string, payment_bank: string|null}  $selection
     */
    public function execute(Order $order, array $selection): string
    {
        if ($order->payment_method->value !== 'online' || ! config('services.paymongo.enabled')) {
            throw new RuntimeException('Direct online banking is unavailable.');
        }

        $bank = $selection['payment_bank'] ?? '';
        if (! isset(self::BANKS[$bank])) {
            throw ValidationException::withMessages(['payment_bank' => 'Choose a supported online bank.']);
        }

        $amountMinor = (int) bcmul($order->total_amount, '100', 0);
        if ($amountMinor < self::BANKS[$bank]['minimum'] || $amountMinor > self::BANKS[$bank]['maximum']) {
            throw ValidationException::withMessages(['payment_bank' => 'This bank cannot process the order total. Choose another payment method.']);
        }

        $intentAttributes = [
            'amount' => $amountMinor,
            'currency' => 'PHP',
            'payment_method_allowed' => [self::BANKS[$bank]['type']],
            'description' => 'Battlefront order '.$order->reference,
            'metadata' => ['order_id' => (string) $order->id, 'bank_code' => $bank],
        ];
        $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->first();
        $intentOperation = null;
        if ($flow !== null) {
            if ($flow->channel !== 'direct_online_banking' || $flow->bank_code !== $bank || (int) $flow->amount_minor !== $amountMinor) {
                throw new RuntimeException('The payment is already active or requires reconciliation.');
            }
            if ($flow->checkout_url !== null && $flow->status === 'open') {
                return $flow->checkout_url;
            }
            if ($flow->status === 'reconciliation_required' && $flow->payment_intent_id !== null) {
                return $this->recoverKnownIntent($order, $flow);
            }
            if ($flow->status === 'reconciliation_required') {
                $intentOperation = $flow->operations()->where('kind', 'create_payment_intent')->latest('id')->first();
                if ($intentOperation === null
                    || ! in_array($intentOperation->status, ['unknown', 'dispatched'], strict: true)
                    || $intentOperation->request_fingerprint !== hash('sha256', json_encode($intentAttributes, JSON_THROW_ON_ERROR))
                    || ! is_string($intentOperation->dispatched_at)
                    || ! Carbon::parse($intentOperation->dispatched_at)->addHours(23)->isFuture()) {
                    throw new RuntimeException('The bank payment reference is unknown and requires reconciliation.');
                }
            } else {
                throw new RuntimeException('The payment is already active or requires reconciliation.');
            }
        } else {
            $intentOperation = $this->persistOperation($order, $amountMinor, 'create_payment_intent', $intentAttributes, 'direct_online_banking', $bank);
            $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->firstOrFail();
        }

        try {
            $operationBeingExecuted = $intentOperation;
            $intent = $this->client->ordersApiRequest()
                ->withHeaders(['Idempotency-Key' => $intentOperation->idempotency_key])
                ->post('/v1/payment_intents', ['data' => ['attributes' => $intentAttributes]])
                ->throw()
                ->json('data');
            $intentId = data_get($intent, 'id');
            $clientKey = data_get($intent, 'attributes.client_key');

            if (! is_string($intentId) || ! is_string($clientKey)) {
                throw new RuntimeException('PayMongo returned an invalid payment intent.');
            }

            $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->firstOrFail();
            $attempt = DB::transaction(function () use ($flow, $intentId, $bank, $intentOperation): PayMongoAttempt {
                $flow->update(['payment_intent_id' => $intentId]);
                $intentOperation->update(['status' => 'completed', 'provider_reference' => $intentId, 'completed_at' => now()]);

                return $flow->attempts()->create([
                    'payment_intent_id' => $intentId,
                    'bank_code' => $bank,
                    'status' => 'creating',
                ]);
            });

            $methodAttributes = ['type' => self::BANKS[$bank]['type'], 'details' => ['bank_code' => $bank]];
            $methodOperation = $this->persistFlowOperation($flow, 'create_payment_method', $methodAttributes);
            $operationBeingExecuted = $methodOperation;
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
            $attempt->update(['payment_method_id' => $methodId]);

            $attachAttributes = [
                'payment_method' => $methodId,
                'client_key' => $clientKey,
                'return_url' => route('orders.show', ['order' => $order, 'paymongo_return' => 1]),
            ];
            $attachOperation = $this->persistFlowOperation($flow, 'attach_payment_method', $attachAttributes);
            $operationBeingExecuted = $attachOperation;
            $attachedIntent = $this->client->ordersApiPublicRequest()
                ->withHeaders(['Idempotency-Key' => $attachOperation->idempotency_key])
                ->post('/v1/payment_intents/'.$intentId.'/attach', ['data' => ['attributes' => $attachAttributes]])
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
                $flow->update(['checkout_url' => $redirectUrl, 'status' => 'open']);
                $attempt->update([
                    'payment_id' => is_string($paymentId) ? $paymentId : null,
                    'status' => data_get($attachedIntent, 'attributes.status', 'awaiting_next_action'),
                    'redirect_url' => $redirectUrl,
                ]);
                $attachOperation->update(['status' => 'completed', 'provider_reference' => $attempt->payment_intent_id, 'completed_at' => now()]);
            });

            return $redirectUrl;
        } catch (Throwable $exception) {
            $definitiveFailure = $this->isDefinitiveClientFailure($exception);
            $operationBeingExecuted->update([
                'status' => $definitiveFailure ? 'failed' : 'unknown',
                'next_check_at' => now()->addMinute(),
            ]);
            PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->update([
                'status' => $definitiveFailure && PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->whereNull('payment_intent_id')->exists()
                    ? 'failed'
                    : 'reconciliation_required',
            ]);

            throw new RuntimeException('Direct online payment is pending reconciliation. Your order is saved and inventory remains reserved.', previous: $exception);
        }
    }

    private function recoverKnownIntent(Order $order, PayMongoFlow $flow): string
    {
        $intent = $this->client->ordersApiRequest()
            ->get('/v1/payment_intents/'.$flow->payment_intent_id)
            ->throw()
            ->json('data');

        if (! is_array($intent)
            || data_get($intent, 'attributes.livemode') !== false
            || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
            || data_get($intent, 'attributes.currency') !== $flow->currency) {
            throw new RuntimeException('PayMongo payment state could not be verified.');
        }

        if (data_get($intent, 'attributes.status') === 'awaiting_next_action') {
            $redirectUrl = data_get($intent, 'attributes.next_action.redirect.url');
            if (! is_string($redirectUrl)
                || parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https'
                || ! is_string(parse_url($redirectUrl, PHP_URL_HOST))
                || parse_url($redirectUrl, PHP_URL_USER) !== null
                || parse_url($redirectUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('PayMongo bank redirect could not be recovered.');
            }
            $attempt = $flow->attempts()->latest('attempt_number')->first();
            if ($attempt !== null) {
                $attempt->update(['status' => 'awaiting_next_action', 'redirect_url' => $redirectUrl]);
            }
            $flow->update(['status' => 'open', 'checkout_url' => $redirectUrl]);

            return $redirectUrl;
        }

        if (data_get($intent, 'attributes.status') === 'awaiting_payment_method') {
            $attempt = $flow->attempts()->latest('attempt_number')->first();
            if ($attempt === null) {
                $flow->attempts()->create([
                    'payment_intent_id' => $flow->payment_intent_id,
                    'attempt_number' => 1,
                    'bank_code' => $flow->bank_code,
                    'status' => 'failed',
                ]);
            } else {
                $attempt->update(['status' => 'failed']);
            }
            $flow->update(['status' => 'awaiting_payment_method']);

            return $this->retryDirectOnlineBanking->execute($order);
        }

        throw new RuntimeException('PayMongo payment is still processing or needs reconciliation.');
    }

    private function isDefinitiveClientFailure(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->status() >= 400
            && $exception->response->status() < 500
            && $exception->response->status() !== 409;
    }

    /** @param array<string, mixed> $attributes */
    private function persistOperation(Order $order, int $amountMinor, string $kind, array $attributes, string $channel, string $bank): PayMongoOperation
    {
        return DB::transaction(function () use ($order, $amountMinor, $kind, $attributes, $channel, $bank): PayMongoOperation {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where('active_slot', 'active')->exists()) {
                throw new RuntimeException('A payment flow already exists for this order.');
            }

            $flow = PayMongoFlow::query()->create([
                'order_id' => $order->id,
                'active_slot' => 'active',
                'mode' => 'test',
                'channel' => $channel,
                'bank_code' => $bank,
                'status' => 'creating',
                'amount_minor' => $amountMinor,
                'currency' => 'PHP',
                'merchant_cutoff_at' => $order->created_at->copy()->addDay(),
            ]);

            return $flow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => $kind,
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function persistFlowOperation(PayMongoFlow $flow, string $kind, array $attributes): PayMongoOperation
    {
        $operation = $flow->operations()->create([
            'operation_id' => (string) Str::uuid(),
            'kind' => $kind,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);

        return $operation;
    }
}
