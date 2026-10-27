<?php

namespace App\Services;

use App\Actions\Fortify\IssueEmailOtp;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EmailOtpVerification
{
    public function __construct(private IssueEmailOtp $issueCode) {}

    private const MAX_ATTEMPTS = 5;

    private const INVALID_CODE = 'The code is invalid, expired, or unavailable. Request a new code or return to login.';

    /** @return array<string, mixed> */
    public function start(User $user, string $ip): array
    {
        $token = bin2hex(random_bytes(32));
        $result = DB::transaction(function () use ($user, $ip, $token): ?array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($lockedUser === null || ! $lockedUser->is_active || $lockedUser->email_verified_at !== null) {
                return null;
            }

            $challenge = EmailOtpChallenge::query()->where('user_id', $lockedUser->id)->lockForUpdate()->first()
                ?? new EmailOtpChallenge;
            if ($challenge->email !== $lockedUser->email) {
                $challenge->code_hash = null;
                $challenge->expires_at = null;
            }
            $challenge->user_id = $lockedUser->id;
            $challenge->email = $lockedUser->email;
            $challenge->context_hash = hash('sha256', $token);
            $challenge->context_expires_at = now()->addMinutes(30);
            $challenge->attempts ??= 0;
            $challenge->save();

            if (! $this->hasUsableCode($challenge) && $this->cooldown($challenge) === 0) {
                $retryAfter = $this->issue($challenge, $ip);
                if ($retryAfter > 0) {
                    return $this->metadata($challenge, $retryAfter);
                }
            }

            return $this->metadata($challenge);
        }, attempts: 1);

        if ($result === null) {
            $this->invalidCode();
        }

        return [...$result, 'verification_token' => $token];
    }

    /** @return array<string, mixed>|null */
    public function details(#[SensitiveParameter] string $token): ?array
    {
        $challenge = $this->findContext($token);
        if ($challenge === null) {
            return null;
        }
        $user = User::query()->find($challenge->user_id);

        return $this->contextIsValid($challenge, $user, $token) ? $this->metadata($challenge) : null;
    }

    /** @return array<string, mixed> */
    public function resend(#[SensitiveParameter] string $token, string $ip): array
    {
        $existing = $this->findContext($token);
        if ($existing === null) {
            $this->invalidCode();
        }

        $result = DB::transaction(function () use ($existing, $token, $ip): array {
            $user = User::query()->whereKey($existing->user_id)->lockForUpdate()->first();
            $challenge = EmailOtpChallenge::query()->where('user_id', $existing->user_id)->lockForUpdate()->first();
            if ($challenge === null || $user === null || ! $this->contextIsValid($challenge, $user, $token)) {
                return ['invalid' => true];
            }
            $retryAfter = $this->cooldown($challenge);
            if ($retryAfter > 0) {
                return ['retry_after' => $retryAfter];
            }
            $retryAfter = $this->issue($challenge, $ip);
            if ($retryAfter > 0) {
                return ['retry_after' => $retryAfter];
            }

            return $this->metadata($challenge);
        }, attempts: 1);

        if (isset($result['invalid'])) {
            $this->invalidCode();
        }
        if (! isset($result['status'])) {
            throw new TooManyRequestsHttpException($result['retry_after'], 'Please wait before requesting another verification code.');
        }

        return $result;
    }

    public function verify(#[SensitiveParameter] string $token, #[SensitiveParameter] string $code, string $ip): User
    {
        $this->limitVerification('ip:'.hash('sha256', $ip), 30);
        $existing = $this->findContext($token);
        if ($existing === null) {
            $this->invalidCode();
        }

        $verifiedUser = DB::transaction(function () use ($existing, $token, $code): ?User {
            $user = User::query()->whereKey($existing->user_id)->lockForUpdate()->first();
            $challenge = EmailOtpChallenge::query()->where('user_id', $existing->user_id)->lockForUpdate()->first();
            if ($challenge === null || $user === null || ! $this->contextIsValid($challenge, $user, $token)) {
                return null;
            }
            $this->limitVerification('account:'.$user->id, 10);
            if (! $this->hasUsableCode($challenge)) {
                return null;
            }

            $challenge->attempts++;
            $challenge->save();
            if (preg_match('/\A[0-9]{6}\z/', $code) !== 1 || ! Hash::check($code, $challenge->code_hash)) {
                return null;
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            $challenge->delete();

            return $user;
        }, attempts: 1);

        if ($verifiedUser === null) {
            $this->invalidCode();
        }

        return $verifiedUser;
    }

    private function findContext(#[SensitiveParameter] string $token): ?EmailOtpChallenge
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        return EmailOtpChallenge::query()->where('context_hash', hash('sha256', $token))->first();
    }

    private function contextIsValid(?EmailOtpChallenge $challenge, ?User $user, #[SensitiveParameter] string $token): bool
    {
        return $challenge !== null && $user !== null && $user->is_active && $user->email_verified_at === null
            && $challenge->email === $user->email && $challenge->context_expires_at->isFuture()
            && hash_equals($challenge->context_hash, hash('sha256', $token));
    }

    private function hasUsableCode(EmailOtpChallenge $challenge): bool
    {
        return $challenge->code_hash !== null && $challenge->expires_at?->isFuture() === true
            && $challenge->attempts < self::MAX_ATTEMPTS;
    }

    private function cooldown(EmailOtpChallenge $challenge): int
    {
        return max(0, ($challenge->issued_at?->getTimestamp() ?? 0) + 60 - now()->getTimestamp());
    }

    private function issue(EmailOtpChallenge $challenge, string $ip): int
    {
        $limits = ['account:'.$challenge->user_id => 5, 'ip:'.hash('sha256', $ip) => 20];
        foreach ($limits as $key => $maximum) {
            if (RateLimiter::tooManyAttempts('email-otp:issue:'.$key, $maximum)) {
                return max(1, RateLimiter::availableIn('email-otp:issue:'.$key));
            }
        }
        foreach ($limits as $key => $maximum) {
            if (RateLimiter::increment('email-otp:issue:'.$key, 3600) > $maximum) {
                return max(1, RateLimiter::availableIn('email-otp:issue:'.$key));
            }
        }

        $previousHash = $challenge->code_hash;
        $challenge->code_hash = null;
        $challenge->attempts = 0;
        $challenge->issued_at = now();
        $challenge->expires_at = now()->addMinutes(5);
        $challenge->save();

        $challenge->code_hash = $this->issueCode->execute($challenge->email, $previousHash);
        if ($challenge->code_hash === null) {
            $challenge->expires_at = null;
        }
        $challenge->save();

        return 0;
    }

    private function limitVerification(string $key, int $maximum): void
    {
        $key = 'email-otp:verify:'.$key;
        if (RateLimiter::increment($key, 60) > $maximum) {
            throw new TooManyRequestsHttpException(max(1, RateLimiter::availableIn($key)), 'Too many verification attempts. Please try again later.');
        }
    }

    /** @return array{status: string, masked_email: string, verification_expires_at: string, expires_at: string|null, resend_available_at: string|null, attempts_remaining: int, delivery_status: string, retry_after: int} */
    private function metadata(EmailOtpChallenge $challenge, int $retryAfter = 0): array
    {
        [$local, $domain] = explode('@', $challenge->email, 2);

        return [
            'status' => 'verification_required',
            'masked_email' => mb_substr($local, 0, 1).'***@'.$domain,
            'verification_expires_at' => $challenge->context_expires_at->toIso8601String(),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            'resend_available_at' => $challenge->issued_at?->copy()->addSeconds(60)->toIso8601String(),
            'attempts_remaining' => max(0, self::MAX_ATTEMPTS - $challenge->attempts),
            'delivery_status' => $retryAfter > 0 ? 'rate_limited' : ($challenge->code_hash === null ? 'failed' : 'sent'),
            'retry_after' => max($retryAfter, $this->cooldown($challenge)),
        ];
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
    }
}
