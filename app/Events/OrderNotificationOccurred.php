<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class OrderNotificationOccurred implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $id,
        public string $kind,
        public int $orderId,
        public int $customerId,
        public string $orderReference,
        public string $occurredAt,
    ) {}

    public function audience(): string
    {
        return in_array($this->kind, ['order.placed', 'payment.proof_submitted'], true)
            ? 'administrator' : 'customer';
    }
}
