<?php

namespace App\Enums;

enum ShippingProfile: string
{
    case Standard = 'standard';
    case Fragile = 'fragile';
    case Bulky = 'bulky';

    public function priority(): int
    {
        return match ($this) {
            self::Standard => 0,
            self::Fragile => 1,
            self::Bulky => 2,
        };
    }
}
