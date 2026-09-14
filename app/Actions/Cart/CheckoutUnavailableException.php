<?php

namespace App\Actions\Cart;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

class CheckoutUnavailableException extends DomainException implements ShouldntReport
{
    public static function emptyCart(): self
    {
        return new self('Add at least one available product before checking out.');
    }

    public static function unavailableItems(): self
    {
        return new self('Review unavailable products or quantities in your cart before checking out.');
    }
}
