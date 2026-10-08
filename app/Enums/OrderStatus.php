<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Get the customer-facing order status label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Get the fulfillment-aware status label shown to customers.
     */
    public function customerLabel(FulfillmentMethod $fulfillmentMethod): string
    {
        return match ($this) {
            self::Processing => 'Processing',
            self::Completed => match ($fulfillmentMethod) {
                FulfillmentMethod::Pickup => 'Picked up / Completed',
                FulfillmentMethod::Delivery => 'Delivered / Completed',
            },
            default => $this->label(),
        };
    }

    /**
     * Get the administrator-approved next order statuses.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    /**
     * Determine whether this status may move to the requested status.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), strict: true);
    }
}
