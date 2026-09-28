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

        return $user;
    }
}
