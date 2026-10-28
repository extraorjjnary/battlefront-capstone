<?php

namespace App\Services\PayMongo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoClient
{
    public function request(): PendingRequest
    {
        return $this->authenticatedRequest('https://api.paymongo.com', config('services.paymongo.secret_key'), 'sk_test_');
    }

    public function publicRequest(): PendingRequest
    {
        return $this->authenticatedRequest('https://api.paymongo.com', config('services.paymongo.public_key'), 'pk_test_');
    }

    public function ordersApiRequest(): PendingRequest
    {
        return $this->authenticatedRequest('https://orders-api.paymongo.com', config('services.paymongo.secret_key'), 'sk_test_');
    }

    public function ordersApiPublicRequest(): PendingRequest
    {
        return $this->authenticatedRequest('https://orders-api.paymongo.com', config('services.paymongo.public_key'), 'pk_test_');
    }

    private function authenticatedRequest(string $baseUrl, mixed $apiKey, string $keyPrefix): PendingRequest
    {
        if (! config('services.paymongo.enabled') || config('services.paymongo.mode') !== 'test'
            || ! is_string($apiKey) || ! str_starts_with($apiKey, $keyPrefix)) {
            throw new RuntimeException('PayMongo test checkout is not configured.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($apiKey, '')
            ->connectTimeout(3)
            ->timeout(10);
    }
}
