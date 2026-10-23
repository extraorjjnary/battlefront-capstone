<?php

namespace App\Services\PayMongo;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StartHostedCheckout
{
    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RecordPayMongoPayment $recordPayment,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    public function execute(Order $order): string
    {
        if ($order->payment_method !== PaymentMethod::Online
            || $order->payment_origin !== 'paymongo'
            || $order->status !== OrderStatus::Pending
            || $order->payment_status !== PaymentStatus::Pending
            || $order->created_at->copy()->addDay()->isPast()
            || ! config('services.paymongo.enabled')) {
            throw new RuntimeException('Online payment is unavailable.');
        }

        $amountMinor = (int) bcmul($order->total_amount, '100', 0);
        $operationId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $attributes = [
            'line_items' => [[
                'name' => 'Battlefront order '.$order->reference,
                'amount' => $amountMinor,
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => $order->hosted_payment_method !== null
                ? [$order->hosted_payment_method]
                : config('services.paymongo.hosted_methods'),
            'success_url' => route('orders.show', ['order' => $order, 'paymongo_return' => 1]),
            'cancel_url' => route('orders.show', ['order' => $order, 'paymongo_return' => 1]),
            'reference_number' => $order->reference,
            'metadata' => ['order_id' => (string) $order->id],
        ];
        if (filter_var($order->user?->email, FILTER_VALIDATE_EMAIL)) {
            $attributes['send_email_receipt'] = true;
        }

        $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->first();
        if ($flow !== null) {
            if ($flow->channel !== 'hosted_checkout'
                || (int) $flow->amount_minor !== $amountMinor
                || $flow->hosted_payment_method !== $order->hosted_payment_method) {
                throw new RuntimeException('The payment is already active or requires reconciliation.');
            }
            if ($flow->checkout_url !== null && $flow->status === 'open') {
                return $flow->checkout_url;
            }
            if ($flow->status === 'reconciliation_required' && $flow->checkout_session_id !== null) {
                return $this->recoverKnownSession($flow);
            }
            if ($flow->status !== 'reconciliation_required' || $flow->checkout_session_id !== null) {
                throw new RuntimeException('The payment is being prepared or requires reconciliation.');
            }

            $operation = $flow->operations()->where('kind', 'create_checkout_session')->latest('id')->first();
            if ($operation === null
                || ! in_array($operation->status, ['unknown', 'dispatched'], strict: true)
                || $operation->request_fingerprint !== hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR))
                || ! is_string($operation->dispatched_at)
                || ! Carbon::parse($operation->dispatched_at)->addHours(23)->isFuture()) {
                throw new RuntimeException('The checkout session reference is unknown and requires reconciliation.');
            }
        } else {
            $operationId = (string) Str::uuid();
            $idempotencyKey = (string) Str::uuid();
            $flow = DB::transaction(function () use ($order, $amountMinor, $attributes, $operationId, $idempotencyKey): PayMongoFlow {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if (PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where('active_slot', 'active')->exists()) {
                    throw new RuntimeException('A payment flow already exists for this order.');
                }
                $flow = PayMongoFlow::query()->create([
                    'order_id' => $lockedOrder->id,
                    'active_slot' => 'active',
                    'mode' => 'test',
                    'channel' => 'hosted_checkout',
                    'hosted_payment_method' => $lockedOrder->hosted_payment_method,
                    'status' => 'creating',
                    'amount_minor' => $amountMinor,
                    'currency' => 'PHP',
                    'merchant_cutoff_at' => $lockedOrder->created_at->copy()->addDay(),
                ]);
                $flow->operations()->create([
                    'operation_id' => $operationId,
                    'kind' => 'create_checkout_session',
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
                    'status' => 'pending',
                ]);

                return $flow;
            });
            $operation = $flow->operations()->firstOrFail();
            $operation->update(['status' => 'dispatched', 'dispatched_at' => now()]);
        }

        $idempotencyKey = $operation->idempotency_key;
        $operation->update(['status' => 'dispatched']);

        try {
            $response = $this->client->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/v2/checkout_sessions', ['data' => ['attributes' => $attributes]])
                ->throw()
                ->json('data');

            $sessionId = data_get($response, 'id');
            $checkoutUrl = data_get($response, 'attributes.checkout_url');

            $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : null;
            if (! is_string($sessionId) || ! is_string($checkoutUrl)
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
                || $checkoutHost !== 'checkout.paymongo.com'
                || parse_url($checkoutUrl, PHP_URL_USER) !== null
                || parse_url($checkoutUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('PayMongo returned an invalid checkout session.');
            }

            DB::transaction(function () use ($flow, $operation, $sessionId, $checkoutUrl): void {
                $flow->update(['checkout_session_id' => $sessionId, 'checkout_url' => $checkoutUrl, 'status' => 'open']);
                $operation->update(['status' => 'completed', 'provider_reference' => $sessionId, 'completed_at' => now()]);
            });

            return $checkoutUrl;
        } catch (Throwable $exception) {
            $definitiveFailure = $this->isDefinitiveClientFailure($exception);
            $operation->update([
                'status' => $definitiveFailure ? 'failed' : 'unknown',
                'next_check_at' => now()->addMinutes(1),
            ]);
            $flow->update(['status' => $definitiveFailure ? 'failed' : 'reconciliation_required']);

            throw new RuntimeException('Payment setup is pending reconciliation. Your order is saved and inventory remains reserved.', previous: $exception);
        }
    }

    private function recoverKnownSession(PayMongoFlow $flow): string
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

        $paid = collect(data_get($session, 'attributes.payments', []))
            ->first(static fn (array $payment): bool => data_get($payment, 'attributes.status') === 'paid');
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

        $sessionStatus = data_get($session, 'attributes.status');
        if ($sessionStatus === 'active') {
            $checkoutUrl = data_get($session, 'attributes.checkout_url');
            if (! is_string($checkoutUrl)
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
                || parse_url($checkoutUrl, PHP_URL_HOST) !== 'checkout.paymongo.com'
                || parse_url($checkoutUrl, PHP_URL_USER) !== null
                || parse_url($checkoutUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('PayMongo checkout URL could not be recovered.');
            }
            $flow->update(['status' => 'open', 'checkout_url' => $checkoutUrl]);

            return $checkoutUrl;
        }

        if ($sessionStatus === 'expired') {
            $flow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);
            throw new RuntimeException('This checkout session has expired.');
        }

        throw new RuntimeException('PayMongo checkout state requires reconciliation.');
    }

    private function isDefinitiveClientFailure(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->status() >= 400
            && $exception->response->status() < 500
            && $exception->response->status() !== 409;
    }
}
