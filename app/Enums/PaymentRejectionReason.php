<?php

namespace App\Enums;

enum PaymentRejectionReason: string
{
    case ImageUnclear = 'image_unclear';
    case AmountMismatch = 'amount_mismatch';
    case TransactionUnverified = 'transaction_unverified';
    case WrongAccountOrReference = 'wrong_account_or_reference';
    case Other = 'other';

    /**
     * Get the customer-facing rejection reason label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ImageUnclear => 'Image is unclear',
            self::AmountMismatch => 'Payment amount does not match',
            self::TransactionUnverified => 'Transaction could not be verified',
            self::WrongAccountOrReference => 'Wrong account or reference',
            self::Other => 'Other',
        };
    }
}
