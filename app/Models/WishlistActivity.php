<?php

namespace App\Models;

use App\Enums\WishlistActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['save_cycle_id', 'product_id', 'event_type', 'occurred_at'])]
class WishlistActivity extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => WishlistActivityType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
