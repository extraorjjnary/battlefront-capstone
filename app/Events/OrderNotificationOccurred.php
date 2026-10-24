<?php

namespace App\Events;

use App\Enums\UserRole;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class OrderNotificationOccurred implements ShouldDispatchAfterCommit
{
    /** @param array<string, int|bool|null>|null $context */
    public function __construct(
        public string $id,
        public string $kind,
        public ?int $orderId,
        public ?int $customerId,
        public ?string $orderReference,
        public string $occurredAt,
        public ?UserRole $cancellationInitiator = null,
        public ?array $context = null,
    ) {}

    public function audience(): string
    {
        if (str_starts_with($this->kind, 'inventory.') || str_starts_with($this->kind, 'damage.')
            || str_starts_with($this->kind, 'supplier_return.') || $this->kind === 'customer_return.requested'
            || $this->kind === 'customer_return.handed_back' || $this->kind === 'order.handler.assigned') {
            return 'administrator';
        }

        return in_array($this->kind, ['order.placed', 'payment.proof_submitted'], true)
            ? 'administrator' : 'customer';
    }

    /** @return list<UserRole> */
    public function audiences(): array
    {
        if (in_array($this->kind, ['customer_return.received', 'customer_return.accepted', 'customer_return.rejected', 'customer_return.resolution_completed'], true)) {
            return [UserRole::Customer, UserRole::Administrator];
        }
        if ($this->kind === 'cancellation.requested') {
            return [UserRole::Customer, UserRole::Administrator];
        }
        if ($this->kind === 'order.cancelled' && ($this->cancellationInitiator ?? null) === UserRole::Customer) {
            return [UserRole::Customer, UserRole::Administrator];
        }

        return [UserRole::from($this->audience())];
    }
}
