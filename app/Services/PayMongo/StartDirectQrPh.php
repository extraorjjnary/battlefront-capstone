<?php

namespace App\Services\PayMongo;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoOperation;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StartDirectQrPh
{
    private const QR_EXPIRY_SECONDS = 1800;

    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RecordPayMongoPayment $recordPayment,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    public function execute(Order $order): void
    {
        if ($order->payment_method->value !== 'online'
            || $order->status !== OrderStatus::Pending
            || $order->payment_status !== PaymentStatus::Pending
            || ! config('services.paymongo.enabled')) {
            throw new RuntimeException('QR Ph payment is unavailable.');
        }

        $amountMinor = (int) bcmul($order->total_amount, '100', 0);
        if ($amountMinor < 100) {
            throw new RuntimeException('QR Ph requires a minimum payment of PHP 1.00.');
        }

        $intentAttributes = [
            'amount' => $amountMinor,
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => 'Battlefront order '.$order->reference,
            'metadata' => ['order_id' => (string) $order->id],
        ];
        $flow = $this->prepareFlow($order, $amountMinor, $intentAttributes);

        if ($flow->merchant_cutoff_at->isPast() || in_array($flow->status, ['closed', 'closing', 'paid'], strict: true)) {
            throw new RuntimeException('This QR Ph payment is no longer available.');
        }

        if ($flow->status === 'open'
            && is_string($flow->qr_code_image_url)
            && $flow->qr_expires_at?->isFuture()) {
            return;
        }

        $operation = $flow->operations()->where('kind', 'create_qr_payment_intent')->first();
        if ($operation === null && $flow->payment_intent_id === null) {
            throw new RuntimeException('The QR Ph payment reference is unknown and requires reconciliation.');
        }

        try {
            $intent = $flow->payment_intent_id === null
                ? $this->createIntent($flow, $operation, $intentAttributes)
                : $this->retrieveIntent($flow);

            $intentStatus = data_get($intent, 'attributes.status');
            if ($intentStatus === 'succeeded') {
                $this->recordSucceededIntent($flow, $intent);

                return;
            }

            if ($intentStatus === 'awaiting_next_action') {
                $this->persistQrFromIntent($flow, $intent, recovered: true);

                return;
            }

            if ($intentStatus !== 'awaiting_payment_method') {
                $flow->update(['status' => 'reconciliation_required']);
                throw new RuntimeException('QR Ph payment is still processing or requires reconciliation.');
            }

            $lastAttach = $flow->operations()->where('kind', 'like', 'attach_qr_method_%')->latest('id')->first();
            $attemptNumber = $lastAttach === null || $lastAttach->status === 'completed'
                ? (int) ($flow->operations()->where('kind', 'like', 'attach_qr_method_%')->count() + 1)
                : (int) substr($lastAttach->kind, strrpos($lastAttach->kind, '_') + 1);
            $this->attachQrMethod($flow, $intent, $attemptNumber);
        } catch (Throwable $exception) {
            $definitiveFailure = $exception instanceof RequestException
                && $exception->response->status() >= 400
                && $exception->response->status() < 500
                && $exception->response->status() !== 409;
            $operation?->refresh();
            if ($operation !== null && ! in_array($operation->status, ['completed', 'failed'], strict: true)) {
                $operation->update([
                    'status' => $definitiveFailure ? 'failed' : 'unknown',
                    'next_check_at' => now()->addMinute(),
                ]);
            }
            if ($flow->fresh()->status !== 'paid') {
                $flow->update([
                    'status' => $definitiveFailure && $flow->fresh()->payment_intent_id === null
                        ? 'failed'
                        : 'reconciliation_required',
                ]);
            }

            if ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'requires reconciliation')) {
                throw $exception;
            }

            throw new RuntimeException('QR Ph setup is pending reconciliation. Your order is saved and inventory remains reserved.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $intentAttributes */
    private function prepareFlow(Order $order, int $amountMinor, array $intentAttributes): PayMongoFlow
    {
        return DB::transaction(function () use ($order, $amountMinor, $intentAttributes): PayMongoFlow {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $flow = PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where('active_slot', 'active')->lockForUpdate()->first();

            if ($flow !== null) {
                if ($flow->channel !== 'direct_qrph' || (int) $flow->amount_minor !== $amountMinor) {
                    throw new RuntimeException('The payment is already active or requires reconciliation.');
                }

                return $flow;
            }

            $flow = PayMongoFlow::query()->create([
                'order_id' => $lockedOrder->id,
                'active_slot' => 'active',
                'mode' => 'test',
                'channel' => 'direct_qrph',
                'status' => 'creating',
                'amount_minor' => $amountMinor,
                'currency' => 'PHP',
                'merchant_cutoff_at' => $lockedOrder->created_at->copy()->addDay(),
            ]);
            $flow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => 'create_qr_payment_intent',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', json_encode($intentAttributes, JSON_THROW_ON_ERROR)),
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);

            return $flow;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function createIntent(PayMongoFlow $flow, ?PayMongoOperation $operation, array $attributes): array
    {
        if ($operation === null) {
            throw new RuntimeException('The QR Ph payment operation requires reconciliation.');
        }
        if (! in_array($operation->status, ['pending', 'dispatched', 'unknown'], strict: true)
            || $operation->request_fingerprint !== hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR))
            || ! is_string($operation->dispatched_at)
            || ! Carbon::parse($operation->dispatched_at)->addHours(23)->isFuture()) {
            throw new RuntimeException('The QR Ph payment reference is unknown and requires reconciliation.');
        }

        $operation->update(['status' => 'dispatched', 'dispatched_at' => $operation->dispatched_at ?? now()]);
        $intent = $this->client->request()
            ->withHeaders(['Idempotency-Key' => $operation->idempotency_key])
            ->post('/v1/payment_intents', ['data' => ['attributes' => $attributes]])
            ->throw()
            ->json('data');
        $intentId = data_get($intent, 'id');

        if (! is_string($intentId)
            || ! is_string(data_get($intent, 'attributes.client_key'))
            || data_get($intent, 'attributes.livemode') !== false
            || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
            || data_get($intent, 'attributes.currency') !== $flow->currency
            || ! in_array('qrph', data_get($intent, 'attributes.payment_method_allowed', []), true)) {
            throw new RuntimeException('PayMongo returned an invalid QR Ph payment intent.');
        }

        $flow->update(['payment_intent_id' => $intentId]);
        $operation->update([
            'status' => 'completed',
            'provider_reference' => $intentId,
            'completed_at' => now(),
        ]);

        return $intent;
    }

    /** @return array<string, mixed> */
    private function retrieveIntent(PayMongoFlow $flow): array
    {
        $intent = $this->client->request()
            ->get('/v1/payment_intents/'.$flow->payment_intent_id)
            ->throw()
            ->json('data');

        if (! is_array($intent)
            || data_get($intent, 'id') !== $flow->payment_intent_id
            || data_get($intent, 'attributes.livemode') !== false
            || data_get($intent, 'attributes.amount') !== (int) $flow->amount_minor
            || data_get($intent, 'attributes.currency') !== $flow->currency
            || ! in_array('qrph', data_get($intent, 'attributes.payment_method_allowed', []), true)) {
            throw new RuntimeException('PayMongo QR Ph state could not be verified.');
        }

        return $intent;
    }

    /** @param array<string, mixed> $intent */
    private function attachQrMethod(PayMongoFlow $flow, array $intent, int $attemptNumber): void
    {
        $methodAttributes = ['type' => 'qrph', 'expiry_seconds' => self::QR_EXPIRY_SECONDS];
        $methodOperation = $this->flowOperation($flow, 'create_qr_method_'.$attemptNumber, $methodAttributes);

        $method = $this->client->publicRequest()
            ->withHeaders(['Idempotency-Key' => $methodOperation->idempotency_key])
            ->post('/v1/payment_methods', ['data' => ['attributes' => $methodAttributes]])
            ->throw()
            ->json('data');
        $methodId = data_get($method, 'id');

        if (! is_string($methodId)) {
            throw new RuntimeException('PayMongo returned an invalid QR Ph payment method.');
        }

        $methodOperation->update(['status' => 'completed', 'provider_reference' => $methodId, 'completed_at' => now()]);
        $attachAttributes = [
            'payment_method' => $methodId,
            'client_key' => data_get($intent, 'attributes.client_key'),
        ];
        $attachOperation = $this->flowOperation($flow, 'attach_qr_method_'.$attemptNumber, $attachAttributes);
        $attachedIntent = $this->client->publicRequest()
            ->post('/v1/payment_intents/'.$flow->payment_intent_id.'/attach', ['data' => ['attributes' => $attachAttributes]])
            ->throw()
            ->json('data');

        if (! is_array($attachedIntent)
            || data_get($attachedIntent, 'id') !== $flow->payment_intent_id
            || data_get($attachedIntent, 'attributes.livemode') !== false
            || data_get($attachedIntent, 'attributes.status') !== 'awaiting_next_action') {
            throw new RuntimeException('PayMongo did not return an active QR Ph payment.');
        }

        $this->persistQrFromIntent($flow, $attachedIntent, recovered: false);
        $attachOperation->update([
            'status' => 'completed',
            'provider_reference' => $flow->payment_intent_id,
            'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $intent */
    private function persistQrFromIntent(PayMongoFlow $flow, array $intent, bool $recovered): void
    {
        $image = data_get($intent, 'attributes.next_action.code.image_url');
        if (! is_string($image)
            || ! preg_match('/\Adata:image\/[a-zA-Z0-9.+-]+;base64,([A-Za-z0-9+\/=]+)\z/', $image, $matches)
            || base64_decode($matches[1], true) === false) {
            throw new RuntimeException('PayMongo QR Ph image could not be verified.');
        }

        $expiresAt = data_get($intent, 'attributes.next_action.code.expires_at');
        if (is_int($expiresAt)) {
            $expiry = Carbon::createFromTimestamp($expiresAt);
        } elseif ($recovered && is_int(data_get($intent, 'attributes.updated_at'))) {
            $expiry = Carbon::createFromTimestamp(data_get($intent, 'attributes.updated_at'))->addSeconds(self::QR_EXPIRY_SECONDS);
        } else {
            $expiry = now()->addSeconds(self::QR_EXPIRY_SECONDS);
        }

        if ($expiry->isPast()) {
            $flow->update(['status' => 'reconciliation_required', 'qr_code_image_url' => null, 'qr_expires_at' => null]);

            return;
        }

        $flow->update([
            'status' => 'open',
            'qr_code_image_url' => $image,
            'qr_expires_at' => $expiry,
            'checkout_url' => null,
        ]);

        if ($recovered) {
            $flow->operations()
                ->whereIn('status', ['dispatched', 'unknown'])
                ->where(function ($query): void {
                    $query->where('kind', 'like', 'create_qr_method_%')
                        ->orWhere('kind', 'like', 'attach_qr_method_%');
                })
                ->update([
                    'status' => 'completed',
                    'provider_reference' => $flow->payment_intent_id,
                    'completed_at' => now(),
                ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function flowOperation(PayMongoFlow $flow, string $kind, array $attributes): PayMongoOperation
    {
        $fingerprint = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($flow, $kind, $fingerprint): PayMongoOperation {
            $lockedFlow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
            $operation = $lockedFlow->operations()->where('kind', $kind)->latest('id')->first();

            if ($operation !== null) {
                if ($operation->request_fingerprint !== $fingerprint
                    || ! in_array($operation->status, ['pending', 'dispatched', 'unknown', 'completed'], strict: true)) {
                    throw new RuntimeException('The QR Ph payment operation requires reconciliation.');
                }

                return $operation;
            }

            return $lockedFlow->operations()->create([
                'operation_id' => (string) Str::uuid(),
                'kind' => $kind,
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => $fingerprint,
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $intent */
    private function recordSucceededIntent(PayMongoFlow $flow, array $intent): void
    {
        $providerPayments = data_get($intent, 'attributes.payments', []);
        $providerPayments = is_array($providerPayments) ? $providerPayments : [];
        $payments = collect($providerPayments)
            ->filter(static fn (mixed $payment): bool => is_array($payment)
                && data_get($payment, 'attributes.status') === 'paid');

        if ($payments->count() !== 1) {
            $flow->update(['status' => 'reconciliation_required']);

            return;
        }

        $paid = $payments->first();
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
        } else {
            $flow->update(['status' => 'reconciliation_required']);
        }
    }
}
