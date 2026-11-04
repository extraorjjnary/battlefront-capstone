<?php

namespace App\Services\PayMongo;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StartHostedCheckout
{
    public function __construct(
        private readonly PayMongoClient $client,
    ) {}

    public function execute(Order $order, bool $allowReplacement = true): string
    {
        $lock = Cache::lock('paymongo-hosted:'.$order->id, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Payment reconciliation is already in progress.');
        }

        try {
            return PaymentRecovery::locked($order->id, fn (): string => $this->start($order->fresh(), $allowReplacement));
        } finally {
            $lock->release();
        }
    }

    private function start(Order $order, bool $allowReplacement): string
    {
        if ($order->payment_method !== PaymentMethod::Online
            || $order->payment_origin !== 'paymongo'
            || $order->status !== OrderStatus::Pending
            || $order->payment_status !== PaymentStatus::Pending
            || $order->payment_closure_requested_at !== null
            || $order->created_at->copy()->addDay()->lessThanOrEqualTo(now())
            || ($allowReplacement && ! config('services.paymongo.enabled'))) {
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
        if (filter_var($order->user->email, FILTER_VALIDATE_EMAIL)) {
            $attributes['send_email_receipt'] = true;
        }

        $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->first();
        if ($flow !== null) {
            if ($flow->channel !== 'hosted_checkout'
                || (int) $flow->amount_minor !== $amountMinor
                || $flow->hosted_payment_method !== $order->hosted_payment_method) {
                throw new RuntimeException('The payment is already active or requires reconciliation.');
            }
            if ($flow->checkout_session_id !== null && in_array($flow->status, ['open', 'reconciliation_required'], true)) {
                $observation = app(ObserveHostedCheckout::class)->execute($flow, $allowReplacement);
                if ($observation->checkoutUrl !== null) {
                    return $observation->checkoutUrl;
                }

                if (! $allowReplacement) {
                    throw new RuntimeException('The previous payment expired. A customer retry is required.');
                }

                if ($observation->requiresFreshTestSession
                    && ! app(CloseHostedCheckout::class)->execute($order->id, $flow->id)) {
                    throw new RuntimeException('The previous test checkout must be confirmed closed before retrying.');
                }

                return $this->start($order->fresh(), $allowReplacement);
            }
            if ($flow->status === 'failed' && $flow->checkout_session_id === null
                && $flow->operations()->where('kind', 'create_checkout_session')->where('status', 'failed')->exists()
                && ! $flow->operations()->whereIn('status', ['pending', 'dispatched', 'unknown'])->exists()) {
                $flow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);

                if (! $allowReplacement) {
                    throw new RuntimeException('A customer retry is required.');
                }

                return $this->start($order->fresh(), $allowReplacement);
            }
            if ($flow->status === 'creating') {
                $flow->update(['status' => 'reconciliation_required']);
            }
            if ($flow->status !== 'reconciliation_required') {
                throw new RuntimeException('The payment is being prepared or requires reconciliation.');
            }

            $operation = $flow->operations()->where('kind', 'create_checkout_session')->latest('id')->first();
            if ($operation !== null && $operation->status === 'pending') {
                $operation->update(['status' => 'dispatched', 'dispatched_at' => now()]);
                $operation->refresh();
            }
            if ($operation === null
                || ! in_array($operation->status, ['unknown', 'dispatched'], strict: true)
                || $operation->request_fingerprint !== hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR))
                || ! is_string($operation->dispatched_at)
                || ! Carbon::parse($operation->dispatched_at)->addHours(23)->isFuture()) {
                throw new RuntimeException('The checkout session reference is unknown and requires reconciliation.');
            }
        } else {
            if (! $allowReplacement) {
                throw new RuntimeException('A customer retry is required.');
            }
            $operationId = (string) Str::uuid();
            $idempotencyKey = (string) Str::uuid();
            $flow = DB::transaction(function () use ($order, $amountMinor, $attributes, $operationId, $idempotencyKey): PayMongoFlow {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($lockedOrder->status !== OrderStatus::Pending
                    || $lockedOrder->payment_closure_requested_at !== null
                    || $lockedOrder->payment_status !== PaymentStatus::Pending
                    || $lockedOrder->created_at->copy()->addDay()->lessThanOrEqualTo(now())
                    || app(OrderProcessingService::class)->hasCancellationHold($lockedOrder)) {
                    throw new RuntimeException('This order cannot accept payment.');
                }
                if (PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where('active_slot', 'active')->exists()) {
                    throw new RuntimeException('A payment flow already exists for this order.');
                }
                if (PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where(fn ($query) => $query->where('status', '!=', 'closed')->orWhere('requires_attention', true))->exists()) {
                    throw new RuntimeException('Previous payment history requires reconciliation.');
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

            Cache::put('paymongo-status-refresh:'.$order->id, true, 5);

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

    private function isDefinitiveClientFailure(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->status() >= 400
            && $exception->response->status() < 500
            && ! in_array($exception->response->status(), [408, 409, 429], true);
    }
}
