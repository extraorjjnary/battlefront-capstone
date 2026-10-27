<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\AuthenticateVerifiedCustomer;
use App\Http\Controllers\Controller;
use App\Services\EmailOtpVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EmailVerificationController extends Controller
{
    public function __construct(private EmailOtpVerification $verification) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $details = $this->verification->details($request->session()->get('email_verification.token', ''));

        if ($details === null) {
            return $this->restart($request);
        }

        $resendWait = max(0, $request->session()->get('email_verification.resend_limited_until', 0) - now()->getTimestamp());
        if ($resendWait > 0) {
            $details['resend_available_at'] = now()->addSeconds($resendWait)->toIso8601String();
            $details['retry_after'] = $resendWait;
            $details['delivery_status'] = 'rate_limited';
        }

        $response = Inertia::render('auth/VerifyEmail', [
            'verification' => $details,
            'status' => $request->session()->get('status'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function store(Request $request, AuthenticateVerifiedCustomer $authenticateCustomer, LoginResponse $loginResponse): Response
    {
        $token = $request->session()->get('email_verification.token', '');
        if ($this->verification->details($token) === null) {
            return $this->restart($request);
        }

        $validated = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);

        try {
            $user = $this->verification->verify($token, $validated['code'], (string) $request->ip());
        } catch (TooManyRequestsHttpException $exception) {
            return $this->throttled($exception);
        }

        $authenticated = $authenticateCustomer->execute($request, $user, $token);
        $request->session()->forget('email_verification');
        $request->session()->regenerate();

        if ($authenticated) {
            return $loginResponse->toResponse($request);
        }

        return to_route('login')->with('status', 'Your email is verified. You can now log in.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $token = $request->session()->get('email_verification.token', '');
        if ($this->verification->details($token) === null) {
            return $this->restart($request);
        }

        try {
            $data = $this->verification->resend($token, (string) $request->ip());
        } catch (TooManyRequestsHttpException $exception) {
            $request->session()->put('email_verification.resend_limited_until',
                now()->getTimestamp() + max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60)));

            return $this->throttled($exception);
        }

        $request->session()->forget('email_verification.resend_limited_until');

        if ($data['delivery_status'] === 'failed') {
            return to_route('verification.notice')->withErrors([
                'code' => 'We could not send your verification email. Please wait for the resend timer and try again.',
            ]);
        }

        return to_route('verification.notice')->with('status', 'A new verification code has been sent. Check your inbox.');
    }

    private function restart(Request $request): RedirectResponse
    {
        $request->session()->forget('email_verification');

        return to_route('login')->with('status', 'Please log in to continue email verification.');
    }

    private function throttled(TooManyRequestsHttpException $exception): RedirectResponse
    {
        $seconds = max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60));

        return to_route('verification.notice')
            ->withErrors(['code' => "Please wait {$seconds} seconds before trying again."])
            ->header('Retry-After', (string) $seconds);
    }
}
