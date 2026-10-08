<?php

namespace App\Actions\Recommendation;

use App\Enums\UserRole;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MergeGuestRecommendationHistory
{
    public function __invoke(GuestRecommendationProfile $profile, User $customer): void
    {
        if ($customer->role !== UserRole::Customer) {
            $profile->delete();

            return;
        }

        DB::transaction(function () use ($profile, $customer): void {
            $lockedProfile = GuestRecommendationProfile::query()
                ->whereKey($profile->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedProfile === null) {
                return;
            }

            $now = now();

            if (! $customer->personalized_recommendations_enabled) {
                $lockedProfile->searches()->delete();
                $lockedProfile->productViews()->delete();
                $lockedProfile->delete();

                return;
            }

            $searches = $lockedProfile->searches()->where('expires_at', '>', $now)->get();

            if (! $customer->search_recommendations_enabled) {
                $lockedProfile->searches()->delete();
            } else {
                foreach ($searches as $search) {
                    $recentSearches = $customer->searches()
                        ->where('created_at', '>=', $now->copy()->subMinutes(10))
                        ->get(['query']);
                    $duplicate = $recentSearches->contains(
                        fn (CustomerSearch $recentSearch): bool => $this->isNearlyIdentical(
                            $recentSearch->query,
                            $search->query,
                        ),
                    );

                    if ($duplicate) {
                        $search->delete();

                        continue;
                    }

                    $search->user_id = $customer->getKey();
                    $search->guest_recommendation_profile_id = null;
                    $search->save();
                }

                $lockedProfile->searches()->where('expires_at', '<=', $now)->delete();
            }

            $views = $lockedProfile->productViews()->where('expires_at', '>', $now)->get();

            if (! $customer->product_view_recommendations_enabled) {
                $lockedProfile->productViews()->delete();
            } else {
                foreach ($views as $view) {
                    $duplicate = $customer->productViews()
                        ->where('product_id', $view->product_id)
                        ->where('created_at', '>=', $now->copy()->subMinutes(30))
                        ->exists();

                    if ($duplicate) {
                        $view->delete();

                        continue;
                    }

                    $view->user_id = $customer->getKey();
                    $view->guest_recommendation_profile_id = null;
                    $view->save();
                }

                $lockedProfile->productViews()->where('expires_at', '<=', $now)->delete();
            }

            $lockedProfile->delete();
        });
    }

    private function isNearlyIdentical(string $previousQuery, string $newQuery): bool
    {
        if ($previousQuery === $newQuery) {
            return true;
        }

        return mb_strlen($previousQuery) >= 3
            && mb_strlen($newQuery) >= 3
            && (str_starts_with($previousQuery, $newQuery) || str_starts_with($newQuery, $previousQuery));
    }
}
