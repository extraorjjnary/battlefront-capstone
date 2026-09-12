<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\CartFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Collection<int, CartItem> $items
 */
#[Fillable(['user_id'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /**
     * Get the customer who owns the cart.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the items stored in the cart.
     *
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Register the cart persistence rules.
     */
    protected static function booted(): void
    {
        static::saving(function (Cart $cart): void {
            $belongsToCustomer = User::query()
                ->whereKey($cart->user_id)
                ->where('role', UserRole::Customer->value)
                ->exists();

            if (! $belongsToCustomer) {
                throw new DomainException('Carts may only belong to customers.');
            }
        });
    }
}
