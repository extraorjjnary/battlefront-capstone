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
        unset($validated['email'], $validated['email_verified_at']);

        if (isset($validated['default_delivery_contact_number'])) {
            $validated['default_delivery_contact_number'] = PhilippineMobileNumber::normalize($validated['default_delivery_contact_number']);
        }

        $user->fill($validated);

        $user->save();

        return $user;
    }
}
