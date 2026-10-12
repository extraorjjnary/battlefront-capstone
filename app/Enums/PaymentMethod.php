<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case CardAtStore = 'card_at_store';
    case GCash = 'gcash';
    case Maya = 'maya';
    case Cod = 'cod';

    /**
     * Get the customer-facing payment label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash (Pay at Pickup)',
            self::CardAtStore => 'Debit/Credit Card (Pay at Store)',
            self::GCash => 'GCash',
            self::Maya => 'Maya',
            self::Cod => 'Cash on Delivery (COD)',
        };
    }

    /**
     * Determine whether this payment method needs uploaded evidence.
     */
    public function requiresPaymentProof(): bool
    {
        return match ($this) {
            self::GCash, self::Maya => true,
            self::Cash, self::CardAtStore, self::Cod => false,
        };
    }

    public function isPayAtPickup(): bool
    {
        return in_array($this, [self::Cash, self::CardAtStore], strict: true);
    }

    public function description(): ?string
    {
        return match ($this) {
            self::Cash => 'Pay cash when collecting your products at the store. No advance payment or uploaded proof is required.',
            self::CardAtStore => 'Pay through a physical debit/credit card terminal during pickup at the store. This is not an online payment gateway; no advance payment or uploaded proof is required.',
            default => null,
        };
    }

    /**
     * Determine whether this payment method supports the fulfillment method.
     */
    public function isAvailableFor(FulfillmentMethod $fulfillmentMethod): bool
    {
        return match ($fulfillmentMethod) {
            FulfillmentMethod::Pickup => $this !== self::Cod,
            FulfillmentMethod::Delivery => in_array($this, [self::GCash, self::Maya, self::Cod], strict: true),
        };
    }
}
