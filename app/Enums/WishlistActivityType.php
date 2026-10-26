<?php

namespace App\Enums;

enum WishlistActivityType: string
{
    case Added = 'added';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'Added',
            self::Removed => 'Removed',
        };
    }
}
