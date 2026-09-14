<?php

namespace App\Actions\Order;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

class OrderPlacementException extends DomainException implements ShouldntReport
{
    public static function emptyCart(): self
    {
        return new self('Add at least one available product before placing an order.');
    }

    public static function unavailableItems(): self
    {
        return new self('Review unavailable products or quantities in your cart before placing an order.');
    }
}
