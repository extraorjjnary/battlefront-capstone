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

            if ($lockedProfile->expires_at->lte($now) || ! $customer->personalized_recommendations_enabled) {
                $lockedProfile->searches()->delete();
                $lockedProfile->productViews()->delete();
                $lockedProfile->delete();

                return;
            }

            if (! $customer->search_recommendations_enabled) {
                $lockedProfile->searches()->delete();
            } else {
                $recentSearches = $customer->searches()
                    ->where('expires_at', '>', $now)
                    ->where('created_at', '>=', $now->copy()->subMinutes(10))
                    ->get(['query']);

                foreach ($lockedProfile->searches()->where('expires_at', '>', $now)->lazyById(100) as $search) {
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

                    if ($search->created_at->gte($now->copy()->subMinutes(10))) {
                        $recentSearches->push($search);
                    }
                }

                $lockedProfile->searches()->where('expires_at', '<=', $now)->delete();
            }

            if (! $customer->product_view_recommendations_enabled) {
                $lockedProfile->productViews()->delete();
            } else {
                $recentViews = $customer->productViews()
                    ->where('expires_at', '>', $now)
                    ->where('created_at', '>=', $now->copy()->subMinutes(30))
                    ->orderBy('id')
                    ->get()->keyBy('product_id');

                foreach ($lockedProfile->productViews()->where('expires_at', '>', $now)->lazyById(100) as $view) {
                    $duplicate = $recentViews->get($view->product_id);

                    if ($duplicate) {
                        if ($view->dwell_seconds > $duplicate->dwell_seconds) {
                            $duplicate->dwell_seconds = $view->dwell_seconds;
                            $duplicate->save();
                        }
                        $view->delete();

                        continue;
                    }

                    $view->user_id = $customer->getKey();
                    $view->guest_recommendation_profile_id = null;
                    $view->save();

                    if ($view->created_at->gte($now->copy()->subMinutes(30))) {
                        $recentViews->put($view->product_id, $view);
                    }
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
