<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\EmailOtpVerification;
use Illuminate\Http\Request;

class BeginEmailVerification
{
    public function __construct(private EmailOtpVerification $verification) {}

    /** @return array<string, mixed> */
    public function execute(Request $request, User $user): array
    {
        $data = $this->verification->start($user, (string) $request->ip());

        $request->session()->regenerate();
        $request->session()->forget('email_verification');
        $request->session()->put('email_verification.token', $data['verification_token']);
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
