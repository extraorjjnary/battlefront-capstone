<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmailOtpVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, EmailOtpVerification $verification): JsonResponse
    {
        $validated = $request->validate([
            'verification_token' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);

        $verification->verify($validated['verification_token'], $validated['code'], (string) $request->ip());

        return response()->json([
            'message' => 'Email verified. You can now sign in.',
            'data' => ['status' => 'verified'],
        ])->header('Cache-Control', 'no-store');
    }

    public function resend(Request $request, EmailOtpVerification $verification): JsonResponse
    {
        $validated = $request->validate([
            'verification_token' => ['required', 'string', 'max:255'],
        ]);
        $details = $verification->resend($validated['verification_token'], (string) $request->ip());

        $status = match ($details['delivery_status']) {
            'failed' => 503,
            'rate_limited' => 429,
            default => 200,
        };
        $message = match ($status) {
            503 => 'We could not send the verification email. Please try again later.',
            429 => 'Please wait before requesting another code.',
            default => 'A new verification code has been sent.',
        };

        return response()->json(['message' => $message, 'data' => $details], $status)
            ->header('Cache-Control', 'no-store')
            ->withHeaders($status === 429 ? ['Retry-After' => (string) $details['retry_after']] : []);
    }
}
