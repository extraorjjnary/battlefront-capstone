<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;

class AuthenticateVerifiedRegistration
{
    public function execute(Request $request, User $user, #[SensitiveParameter] string $verificationToken): bool
    {
        $registration = $request->session()->pull('email_verification.registration');

        if (! is_array($registration)
            || ($registration['user_id'] ?? null) !== $user->id
            || ($registration['email'] ?? null) !== $user->email
            || ! is_string($registration['context_hash'] ?? null)
            || ! hash_equals($registration['context_hash'], hash('sha256', $verificationToken))
            || ! is_string($registration['password_fingerprint'] ?? null)
            || ! hash_equals($registration['password_fingerprint'], hash_hmac('sha256', $user->getAuthPassword(), (string) config('app.key')))
            || ! $user->is_active
            || $user->role !== UserRole::Customer
            || ! $user->hasVerifiedEmail()) {
            return false;
        }

        Auth::guard(config('fortify.guard', 'web'))->login($user, remember: false);

        return true;
    }
}
