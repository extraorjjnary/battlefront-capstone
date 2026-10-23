<?php

namespace App\Services\PayMongo;

use Illuminate\Http\Request;

class VerifyPayMongoWebhook
{
    public function handle(Request $request): ?array
    {
        $mode = config('services.paymongo.mode');
        $secret = config('services.paymongo.webhook_secret');
        $parts = [];

        foreach (explode(',', (string) $request->header('Paymongo-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $mode === 'test' ? ($parts['te'] ?? '') : ($parts['li'] ?? '');
        $rawBody = $request->getContent();

        if (! is_string($secret) || $secret === '' || ! ctype_digit($timestamp) || $signature === ''
            || abs(now()->timestamp - (int) $timestamp) > 300
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || data_get($payload, 'data.attributes.livemode') !== false) {
            return null;
        }

        $eventId = data_get($payload, 'data.id');
        $eventType = data_get($payload, 'data.attributes.type');
        $resource = data_get($payload, 'data.attributes.data');

        if (! is_string($eventId) || ! is_string($eventType) || ! is_array($resource)) {
            return null;
        }

        $attributes = data_get($resource, 'attributes', []);
        $resourceType = data_get($resource, 'type');
        $paymentResources = $resourceType === 'payment'
            ? [$resource]
            : data_get($attributes, 'payments', []);
        $payments = collect($paymentResources)->map(static fn (array $payment): array => [
            'id' => data_get($payment, 'id'),
            'status' => data_get($payment, 'attributes.status'),
            'amount' => data_get($payment, 'attributes.amount'),
            'net_amount' => data_get($payment, 'attributes.net_amount'),
            'currency' => data_get($payment, 'attributes.currency'),
            'method' => data_get($payment, 'attributes.source.type'),
        ])->all();

        if (! in_array($eventType, ['checkout_session.payment.paid', 'payment.paid', 'payment.failed', 'qrph.expired'], strict: true)) {
            return [
                'mode' => 'test',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'resource_id' => data_get($resource, 'id'),
                'verified_data' => ['attributes' => []],
            ];
        }

        return [
            'mode' => 'test',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'resource_id' => data_get($resource, 'id'),
            'verified_data' => [
                'id' => data_get($resource, 'id'),
                'attributes' => [
                    'payment_intent_id' => data_get($attributes, 'payment_intent.id')
                        ?? data_get($attributes, 'payment_intent_id')
                        ?? ($resourceType === 'payment_intent' ? data_get($resource, 'id') : null),
                    'reference_number' => data_get($attributes, 'reference_number'),
                    'payments' => $payments,
                ],
            ],
        ];
    }
}
