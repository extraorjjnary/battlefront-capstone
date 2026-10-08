<?php

namespace App\Actions\User;

use App\Models\User;

class UpdateProfile
{
    /**
     * Save validated profile fields for the selected user.
     *
     * @param  array<string, mixed>  $validated
     */
    public function __invoke(User $user, array $validated): User
    {
        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if (
            array_key_exists('personalized_recommendations_enabled', $validated)
            && ! (bool) $validated['personalized_recommendations_enabled']
        ) {
            $user->searches()->delete();
            $user->productViews()->delete();
        }

        if (
            array_key_exists('search_recommendations_enabled', $validated)
            && ! (bool) $validated['search_recommendations_enabled']
        ) {
            $user->searches()->delete();
        }

        if (
            array_key_exists('product_view_recommendations_enabled', $validated)
            && ! (bool) $validated['product_view_recommendations_enabled']
        ) {
            $user->productViews()->delete();
        }

        return $user;
    }
}
