<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerSearchFactory;
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
 * @property string $query
 * @property Carbon|CarbonImmutable $expires_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[Fillable(['query', 'expires_at', 'guest_recommendation_profile_id'])]
class CustomerSearch extends Model
{
    /** @use HasFactory<CustomerSearchFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(static function (self $search): void {
            if (($search->user_id === null) === ($search->guest_recommendation_profile_id === null)) {
                throw new LogicException('A customer search must have exactly one owner.');
            }
        });
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

    /**
     * Get the customer who made the search.
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
}
