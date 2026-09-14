<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case CardAtStore = 'card_at_store';
    case GCash = 'gcash';
    case Maya = 'maya';

    /**
     * Get the customer-facing payment label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::CardAtStore => 'Card at store',
            self::GCash => 'GCash',
            self::Maya => 'Maya',
        };
    }

    /**
     * Determine whether this payment method needs uploaded evidence.
     */
    public function requiresPaymentProof(): bool
    {
        return match ($this) {
            self::GCash, self::Maya => true,
            self::Cash, self::CardAtStore => false,
        };
    }

    /**
     * Determine whether this payment method supports the fulfillment method.
     */
    public function isAvailableFor(FulfillmentMethod $fulfillmentMethod): bool
    {
        return $fulfillmentMethod === FulfillmentMethod::Pickup
            || in_array($this, [self::GCash, self::Maya], strict: true);
    }
}
