<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;

class RecordCustomerProductView
{
    public function __invoke(?User $user, Product $product, ?GuestRecommendationProfile $guestProfile = null): bool
    {
        if (
            ($user === null && $guestProfile === null)
            || ($user !== null && $user->role !== UserRole::Customer)
            || ($user !== null && ! $user->product_view_recommendations_enabled)
        ) {
            return false;
        }

        $owner = $user ?? $guestProfile;
        $recentlyRecorded = $owner->productViews()
            ->whereBelongsTo($product)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($recentlyRecorded) {
            return false;
        }

        $owner->productViews()->create([
            'product_id' => $product->id,
            'expires_at' => now()->addDays(90),
        ]);

        return true;
    }
}
