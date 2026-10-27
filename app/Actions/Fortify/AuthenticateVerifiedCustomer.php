<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;

class AuthenticateVerifiedCustomer
{
    public function execute(Request $request, User $user, #[SensitiveParameter] string $verificationToken): bool
    {
        $authentication = $request->session()->pull('email_verification.authentication');

        if (! is_array($authentication)
            || ($authentication['user_id'] ?? null) !== $user->id
            || ($authentication['email'] ?? null) !== $user->email
            || ! is_string($authentication['context_hash'] ?? null)
            || ! hash_equals($authentication['context_hash'], hash('sha256', $verificationToken))
            || ! is_string($authentication['password_fingerprint'] ?? null)
            || ! hash_equals($authentication['password_fingerprint'], hash_hmac('sha256', $user->getAuthPassword(), (string) config('app.key')))
            || ! $user->is_active
            || $user->role !== UserRole::Customer
            || ! $user->hasVerifiedEmail()) {
            return false;
        }

        Auth::guard(config('fortify.guard', 'web'))->login($user, remember: ($authentication['remember'] ?? false) === true);

        return true;
    }
}
