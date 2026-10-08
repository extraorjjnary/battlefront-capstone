<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ExpoPushAdapter
{
    /** @param array<string, mixed> $metadata
     * @return array{status: 'ok', id: string}|array{status: 'error', error_code: string}
     */
    public function send(string $token, array $metadata): array
    {
        $result = $this->request('send', [
            'to' => $token, 'title' => 'Battlefront order update',
            'body' => 'An update is available. Open Battlefront to view your order.',
            'sound' => 'default', 'data' => $metadata,
        ]);
        if ($result['status'] === 'error') {
            return $result;
        }
        if ($result['status'] !== 'ok' || ! isset($result['id'])) {
            throw new RuntimeException('Expo ticket unavailable.');
        }

        return ['status' => 'ok', 'id' => $result['id']];
    }

    /** @return array{status: 'ok', id?: string}|array{status: 'error', error_code: string}|array{status: 'pending'} */
    public function receipt(string $ticketId): array
    {
        return $this->request('getReceipts', ['ids' => [$ticketId]], $ticketId);
    }

    /** @param array<string, mixed> $payload
     * @return array{status: 'ok', id?: string}|array{status: 'error', error_code: string}|array{status: 'pending'}
     */
    private function request(string $endpoint, array $payload, ?string $ticketId = null): array
    {
        try {
            $response = $this->client()->post('https://exp.host/--/api/v2/push/'.$endpoint, $payload);
        } catch (Throwable) {
            throw new RuntimeException('Expo connection unavailable.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Expo temporarily unavailable.');
        }
        if (! $response->successful()) {
            return ['status' => 'error', 'error_code' => 'ProviderRequestRejected'];
        }

        $data = $response->json('data');
        if ($ticketId !== null) {
            $data = is_array($data) ? ($data[$ticketId] ?? null) : null;
            if ($data === null) {
                return ['status' => 'pending'];
            }
        }

        if (! is_array($data) || ! in_array($data['status'] ?? null, ['ok', 'error'], true)) {
            throw new RuntimeException('Expo response unavailable.');
        }
        if ($data['status'] === 'error') {
            $code = $data['details']['error'] ?? null;

            return ['status' => 'error', 'error_code' => in_array($code, ['DeviceNotRegistered', 'MessageTooBig', 'MessageRateExceeded', 'MismatchSenderId', 'InvalidCredentials'], true) ? $code : 'ProviderError'];
        }
        if ($ticketId === null && (! is_string($data['id'] ?? null) || $data['id'] === '')) {
            throw new RuntimeException('Expo ticket unavailable.');
        }

        return $ticketId === null ? ['status' => 'ok', 'id' => $data['id']] : ['status' => 'ok'];
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(10);
        $accessToken = config('services.expo.access_token');

        return is_string($accessToken) && $accessToken !== '' ? $client->withToken($accessToken) : $client;
    }
}
