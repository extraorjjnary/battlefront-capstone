<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EmailOtpVerification;
use Illuminate\Http\Request;

class BeginEmailVerification
{
    public function __construct(private EmailOtpVerification $verification) {}

    /** @return array<string, mixed> */
    public function execute(Request $request, User $user, bool $remember = false): array
    {
        $data = $this->verification->start($user, (string) $request->ip());

        $request->session()->regenerate();
        $request->session()->forget('email_verification');
        $request->session()->put('email_verification.token', $data['verification_token']);
        if ($user->role === UserRole::Customer) {
            $request->session()->put('email_verification.authentication', [
                'user_id' => $user->id,
                'email' => $user->email,
                'context_hash' => hash('sha256', $data['verification_token']),
                'password_fingerprint' => hash_hmac('sha256', $user->getAuthPassword(), (string) config('app.key')),
                'remember' => $remember,
            ]);
        }
        if ($data['delivery_status'] === 'rate_limited') {
            $request->session()->put('email_verification.resend_limited_until', now()->getTimestamp() + $data['retry_after']);
        }
        $request->attributes->set('email_verification_response', $data);
        $request->session()->flash('status', match ($data['delivery_status']) {
            'failed' => 'We could not send your verification email. Please wait for the resend timer and try again.',
            'rate_limited' => 'Please wait before requesting another verification code.',
            default => 'Check your inbox for your six-digit verification code.',
        });

        return $data;
    }
}
