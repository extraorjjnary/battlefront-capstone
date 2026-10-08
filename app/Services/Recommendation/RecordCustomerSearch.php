<?php

namespace App\Services\Recommendation;

use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\User;

class RecordCustomerSearch
{
    public function record(?User $user, ?string $query, ?GuestRecommendationProfile $guestProfile = null): void
    {
        $normalizedQuery = mb_strtolower(trim((string) $query));
        $normalizedQuery = preg_replace('/[^\\pL\\pN]+/u', ' ', $normalizedQuery) ?? '';
        $normalizedQuery = trim(preg_replace('/\\s+/u', ' ', $normalizedQuery) ?? '');

        if (
            ($user === null && $guestProfile === null)
            || ($user !== null && $user->role !== UserRole::Customer)
            || ($user !== null && ! $user->personalized_recommendations_enabled)
            || ($user !== null && ! $user->search_recommendations_enabled)
            || $normalizedQuery === ''
        ) {
            return;
        }

        $owner = $user ?? $guestProfile;
        $recentSearch = $owner->searches()
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if ($recentSearch !== null && $this->isNearlyIdentical($recentSearch->query, $normalizedQuery)) {
            $recentSearch->query = $normalizedQuery;
            $recentSearch->expires_at = now()->addDays(90);
            $recentSearch->created_at = now();
            $recentSearch->save();

            return;
        }

        $owner->searches()->create([
            'query' => $normalizedQuery,
            'expires_at' => now()->addDays(90),
        ]);
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
