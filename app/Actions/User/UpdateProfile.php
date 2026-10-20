<?php

namespace App\Actions\User;

use App\Models\User;
use App\Support\PhilippineMobileNumber;

class UpdateProfile
{
    /**
     * Save validated profile fields for the selected user.
     *
     * @param  array<string, mixed>  $validated
     */
    public function __invoke(User $user, array $validated): User
    {
        if (isset($validated['default_delivery_contact_number'])) {
            $validated['default_delivery_contact_number'] = PhilippineMobileNumber::normalize($validated['default_delivery_contact_number']);
        }

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }
}
