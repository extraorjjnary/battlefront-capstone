<?php

namespace App\Services\PayMongo;

final readonly class HostedCheckoutObservation
{
    public function __construct(
        public ?string $checkoutUrl,
        public bool $requiresFreshTestSession = false,
    ) {}
}
