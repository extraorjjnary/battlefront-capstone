<?php

namespace App\Models;

use Database\Factories\CustomerProductViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $guest_recommendation_profile_id
 * @property int $product_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'expires_at', 'guest_recommendation_profile_id'])]
class CustomerProductView extends Model
{
    /** @use HasFactory<CustomerProductViewFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(static function (self $view): void {
            if (($view->user_id === null) === ($view->guest_recommendation_profile_id === null)) {
                throw new LogicException('A customer product view must have exactly one owner.');
            }
        });
    }

    /**
     * Get the customer who viewed the product.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<GuestRecommendationProfile, $this> */
    public function guestRecommendationProfile(): BelongsTo
    {
        return $this->belongsTo(GuestRecommendationProfile::class);
    }

    /**
     * Get the viewed product.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
