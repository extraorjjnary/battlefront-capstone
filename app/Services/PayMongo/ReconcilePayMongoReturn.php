<?php

namespace App\Services\PayMongo;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReconcilePayMongoReturn
{
    public function __construct(
        private readonly PayMongoClient $client,
        private readonly RecordPayMongoPayment $recordPayment,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    public function execute(Order $order): void
    {
        if ($order->payment_origin !== 'paymongo'
            || $order->payment_status !== PaymentStatus::Pending
            || $order->status->value === 'cancelled') {
            return;
        }

        $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->latest('id')->first();

        if ($flow === null) {
            return;
        }

        if (! in_array($flow->channel, ['hosted_checkout', 'direct_online_banking', 'direct_qrph'], strict: true)) {
            return;
        }

        $providerReference = $flow->channel === 'hosted_checkout'
            ? $flow->checkout_session_id
            : $flow->payment_intent_id;

        if (! is_string($providerReference)) {
            return;
        }

        $operation = $flow->operations()->create([
            'operation_id' => (string) Str::uuid(),
            'kind' => 'verify_return_status',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $flow->channel.':'.$providerReference),
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);

        try {
            $resource = $flow->channel === 'hosted_checkout'
                ? $this->client->request()->get('/v1/checkout_sessions/'.$providerReference)->throw()->json('data')
                : ($flow->channel === 'direct_qrph'
                    ? $this->client->request()->get('/v1/payment_intents/'.$providerReference)->throw()->json('data')
                    : $this->client->ordersApiRequest()->get('/v1/payment_intents/'.$providerReference)->throw()->json('data'));

            if (! is_array($resource)
                || data_get($resource, 'id') !== $providerReference
                || data_get($resource, 'attributes.livemode') !== false) {
                throw new RuntimeException('PayMongo returned an unverifiable payment status.');
            }

            $paidPayments = collect(data_get($resource, 'attributes.payments', []))
                ->filter(static fn (array $payment): bool => data_get($payment, 'attributes.status') === 'paid');

            if ($paidPayments->isEmpty()) {
                $operation->update([
                    'status' => 'completed',
                    'provider_reference' => $providerReference,
                    'completed_at' => now(),
                ]);

                return;
            }

            if ($paidPayments->count() > 1) {
                $flow->update(['status' => 'reconciliation_required']);
                $operation->update(['status' => 'unknown', 'next_check_at' => now()->addMinute()]);

                return;
            }

            $paid = $paidPayments->first();
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
                throw new RuntimeException('PayMongo returned incomplete payment details.');
            }

            $this->recordPayment->execute($flow, $payment, $this->notifications);
            $operation->update([
                'status' => 'completed',
                'provider_reference' => $providerReference,
                'completed_at' => now(),
            ]);
        } catch (Throwable) {
            $operation->update(['status' => 'unknown', 'next_check_at' => now()->addMinute()]);
            $flow->update(['status' => 'reconciliation_required']);
        }
    }
}
