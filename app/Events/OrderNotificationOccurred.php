<?php

namespace App\Events;

use App\Enums\UserRole;
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
        public ?UserRole $cancellationInitiator = null,
    ) {}

    public function audience(): string
    {
        return in_array($this->kind, ['order.placed', 'payment.proof_submitted'], true)
            ? 'administrator' : 'customer';
    }

    /** @return list<UserRole> */
    public function audiences(): array
    {
        if ($this->kind === 'order.cancelled' && ($this->cancellationInitiator ?? null) === UserRole::Customer) {
            return [UserRole::Customer, UserRole::Administrator];
        }

        return [UserRole::from($this->audience())];
    }
}
