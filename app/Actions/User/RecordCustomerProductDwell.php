<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;

class RecordCustomerProductDwell
{
    /**
     * Record the longest eligible dwell time for the latest product view.
     */
    public function __invoke(
        ?User $user,
        Product $product,
        ?GuestRecommendationProfile $guestProfile,
        int $dwellSeconds,
    ): void {
        if (
            ($user === null && $guestProfile === null)
            || ($user !== null && $user->role !== UserRole::Customer)
            || ($user !== null && ! $user->personalized_recommendations_enabled)
            || ($user !== null && ! $user->product_view_recommendations_enabled)
        ) {
            return;
        }

        $owner = $user ?? $guestProfile;
        $view = $owner->productViews()
            ->whereBelongsTo($product)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if ($view === null || $dwellSeconds <= $view->dwell_seconds) {
            return;
        }

        $owner->productViews()
            ->whereKey($view->getKey())
            ->where('dwell_seconds', '<', min($dwellSeconds, 3600))
            ->update(['dwell_seconds' => min($dwellSeconds, 3600), 'expires_at' => now()->addDays(90)]);
    }
}
