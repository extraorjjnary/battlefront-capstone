<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use DomainException;

class CartOperationException extends DomainException
{
    /**
     * Create an exception for an unavailable cart product.
     */
    public function __construct(
        public readonly CartAvailability $outcome,
        public readonly ?int $availableQuantity = null,
    ) {
        parent::__construct(match ($outcome) {
            CartAvailability::Available => 'The product is available.',
            CartAvailability::ProductIneligible => 'This product is no longer available.',
            CartAvailability::InventoryUnavailable => 'Stock information is unavailable for this product.',
            CartAvailability::OutOfStock => 'This product is out of stock.',
            CartAvailability::InsufficientStock => "Only {$availableQuantity} item(s) are available.",
        });
    }

    /**
     * Get the input field associated with the failure.
     */
    public function field(): string
    {
        return match ($this->outcome) {
            CartAvailability::ProductIneligible,
            CartAvailability::InventoryUnavailable => 'product_id',
            CartAvailability::OutOfStock,
            CartAvailability::InsufficientStock => 'quantity',
            CartAvailability::Available => 'product_id',
        };
    }
}
