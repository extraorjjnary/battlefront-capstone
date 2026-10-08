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
            ->first();

        if ($view === null || $dwellSeconds <= $view->dwell_seconds) {
            return;
        }

        $view->dwell_seconds = min($dwellSeconds, 3600);
        $view->expires_at = now()->addDays(90);
        $view->save();
    }
}
