<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GuestRecommendationProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token_hash
 * @property Carbon|CarbonImmutable $expires_at
 */
#[Fillable(['token_hash', 'expires_at'])]
class GuestRecommendationProfile extends Model
{
    /** @use HasFactory<GuestRecommendationProfileFactory> */
    use HasFactory;

    /** @return HasMany<CustomerSearch, $this> */
    public function searches(): HasMany
    {
        return $this->hasMany(CustomerSearch::class);
    }

    /** @return HasMany<CustomerProductView, $this> */
    public function productViews(): HasMany
    {
        return $this->hasMany(CustomerProductView::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
