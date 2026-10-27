<?php

namespace App\Services;

use App\Actions\Fortify\IssueEmailOtp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TeamEmailVerification
{
    public function __construct(private IssueEmailOtp $issueCode) {}

    /** @return array<string, mixed> */
    public function send(User $actor, Session $session, string $email, string $ip): array
    {
        $this->authorize($actor);

        return $this->locked($this->contextKey($actor, $session), function () use ($actor, $session, $email, $ip): array {
            return $this->locked('email:'.hash('sha256', $email), function () use ($actor, $session, $email, $ip): array {
                $key = $this->contextKey($actor, $session);
                $state = $this->state($key);
                if ($state === null || $state['email'] !== $email) {
                    $state = [
                        'id' => bin2hex(random_bytes(32)),
                        'email' => $email, 'code_hash' => null, 'attempts' => 0,
                        'issued_at' => null, 'expires_at' => null,
                        'context_expires_at' => now()->addMinutes(30)->getTimestamp(),
                        'verified_at' => null, 'verified_until' => null,
                        'resend_limited_until' => 0,
                    ];
                    $this->persist($key, $state);
                }
                if ($state['verified_at'] !== null) {
                    return $this->metadata($actor, $state);
                }

                $cooldown = (int) Cache::get('team-email:cooldown:'.hash('sha256', $email), 0) - now()->getTimestamp();
                try {
                    if ($cooldown > 0) {
                        $this->limited($cooldown);
                    }
                    $this->limit('send:email:'.hash('sha256', $email), 5, 3600);
                    $this->limit('send:actor:'.$actor->id, 20, 3600);
                    $this->limit('send:ip:'.hash('sha256', $ip), 20, 3600);
                } catch (TooManyRequestsHttpException $exception) {
                    $state['resend_limited_until'] = now()->getTimestamp() + (int) $exception->getHeaders()['Retry-After'];
                    $this->persist($key, $state);
                    throw $exception;
                }

                $previousHash = $state['code_hash'];
                $state['resend_limited_until'] = 0;
                $state['code_hash'] = null;
                $state['attempts'] = 0;
                $state['issued_at'] = now()->getTimestamp();
                $state['expires_at'] = now()->addMinutes(5)->getTimestamp();
                Cache::put('team-email:cooldown:'.hash('sha256', $email), $state['issued_at'] + 60, 60);
                $this->persist($key, $state);

                $state['code_hash'] = $this->issueCode->execute($email, $previousHash, forTeamAccount: true);
                if ($state['code_hash'] === null) {
                    $state['expires_at'] = null;
                }
                $this->persist($key, $state);

                return $this->metadata($actor, $state);
            });
        });
    }

    /** @return array<string, mixed> */
    public function verify(User $actor, Session $session, string $email, #[SensitiveParameter] string $code, string $ip): array
    {
        $this->authorize($actor);
        $this->limit('verify:ip:'.hash('sha256', $ip), 30, 60);
        $this->limit('verify:email:'.hash('sha256', $email), 10, 60);

        return $this->locked($this->contextKey($actor, $session), function () use ($actor, $session, $email, $code): array {
            $key = $this->contextKey($actor, $session);
            $state = $this->state($key);
            if ($state === null || $state['email'] !== $email || $state['verified_at'] !== null
                || $state['code_hash'] === null || $state['expires_at'] <= now()->getTimestamp() || $state['attempts'] >= 5) {
                $this->invalid('code', 'The code is invalid, expired, or unavailable. Request a new code.');
            }

            $state['attempts']++;
            $valid = preg_match('/\A[0-9]{6}\z/', $code) === 1 && Hash::check($code, $state['code_hash']);
            if ($valid) {
                $state['code_hash'] = null;
                $state['verified_at'] = now()->getTimestamp();
                $state['verified_until'] = now()->addMinutes(10)->getTimestamp();
                $state['context_expires_at'] = $state['verified_until'];
            }
            $this->persist($key, $state);
            if (! $valid) {
                $this->invalid('code', 'The code is invalid, expired, or unavailable. Request a new code.');
            }

            return $this->metadata($actor, $state);
        });
    }

    /** @return array<string, mixed>|null */
    public function details(User $actor, Session $session): ?array
    {
        $state = $this->state($this->contextKey($actor, $session));

        return $state === null ? null : $this->metadata($actor, $state);
    }

    public function clear(User $actor, Session $session, string $email): void
    {
        $this->authorize($actor);
        $key = $this->contextKey($actor, $session);
        $this->locked($key, function () use ($key, $email): void {
            if (($this->state($key)['email'] ?? null) === $email) {
                Cache::forget($key);
            }
        });
    }

    /** @param Closure(CarbonImmutable): User $create */
    public function create(User $actor, Session $session, string $email, Closure $create): User
    {
        $this->authorize($actor);
        $key = $this->contextKey($actor, $session);

        return $this->locked($key, function () use ($key, $email, $create): User {
            return $this->locked('email:'.hash('sha256', $email), function () use ($key, $email, $create): User {
                $state = $this->state($key);
                if ($state === null || $state['email'] !== $email || $state['verified_at'] === null
                    || $state['verified_until'] <= now()->getTimestamp()) {
                    Cache::forget($key);
                    $this->invalid('email', 'Verify this email address before creating the account.');
                }

                if (! Cache::add('team-email:used:'.$state['id'], true, CarbonImmutable::createFromTimestamp($state['verified_until']))) {
                    $this->invalid('email', 'This verification has already been used. Verify the email again.');
                }
                try {
                    return $create(CarbonImmutable::createFromTimestamp($state['verified_at']));
                } finally {
                    Cache::forget($key);
                }
            });
        });
    }

    private function authorize(User $actor): void
    {
        $current = $actor->fresh();
        if ($current === null || ! $current->is_active || ! $current->hasVerifiedEmail()
            || ! $current->hasAdministrationPermission('users.manage')) {
            throw new AuthorizationException;
        }
    }

    private function contextKey(User $actor, Session $session): string
    {
        $scope = $session->get('team_email_verification.scope');
        if (! is_string($scope)) {
            $scope = bin2hex(random_bytes(32));
            $session->put('team_email_verification.scope', $scope);
        }

        return 'team-email:context:'.hash('sha256', $actor->id.'|'.$scope);
    }

    /** @return array<string, mixed>|null */
    private function state(string $key): ?array
    {
        $state = Cache::get($key);

        return is_array($state) && $state['context_expires_at'] > now()->getTimestamp() ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function persist(string $key, array $state): void
    {
        Cache::put($key, $state, CarbonImmutable::createFromTimestamp($state['context_expires_at']));
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function metadata(User $actor, array $state): array
    {
        $resendAt = max(($state['issued_at'] ?? 0) + 60,
            (int) Cache::get('team-email:cooldown:'.hash('sha256', $state['email']), 0));
        $retryAfter = max(0, max($resendAt, $state['resend_limited_until']) - now()->getTimestamp());
        foreach (['send:email:'.hash('sha256', $state['email']) => 5, 'send:actor:'.$actor->id => 20] as $key => $maximum) {
            if (RateLimiter::tooManyAttempts('team-email:'.$key, $maximum)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn('team-email:'.$key));
            }
        }

        return [
            'email' => $state['email'],
            'status' => $state['verified_at'] === null ? 'verification_required' : 'verified',
            'expires_at' => $state['expires_at'] === null ? null : CarbonImmutable::createFromTimestamp($state['expires_at'])->toIso8601String(),
            'verified_until' => $state['verified_until'] === null ? null : CarbonImmutable::createFromTimestamp($state['verified_until'])->toIso8601String(),
            'attempts_remaining' => max(0, 5 - $state['attempts']),
            'resend_available_at' => now()->addSeconds($retryAfter)->toIso8601String(),
            'delivery_status' => $retryAfter > 60 ? 'rate_limited' : ($state['code_hash'] === null && $state['verified_at'] === null ? 'failed' : 'sent'),
        ];
    }

    private function limit(string $key, int $maximum, int $seconds): void
    {
        $key = 'team-email:'.$key;
        if (RateLimiter::increment($key, $seconds) > $maximum) {
            $this->limited(max(1, RateLimiter::availableIn($key)));
        }
    }

    private function limited(int $seconds): never
    {
        throw new TooManyRequestsHttpException($seconds, 'Please wait before requesting or checking another verification code.');
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function locked(string $key, Closure $operation): mixed
    {
        try {
            return Cache::lock('team-email:lock:'.hash('sha256', $key), 60)->block(3, $operation);
        } catch (LockTimeoutException) {
            $this->invalid('email', 'Verification is busy. Please try again in a moment.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
