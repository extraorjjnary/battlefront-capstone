<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class AttemptVerifiedAuthentication extends AttemptToAuthenticate
{
    public function __construct(StatefulGuard $guard, LoginRateLimiter $limiter, private BeginEmailVerification $beginVerification)
    {
        parent::__construct($guard, $limiter);
    }

    /**
     * @param  Request  $request
     * @param  callable(Request): mixed  $next
     */
    protected function handleUsingCustomCallback(mixed $request, mixed $next): mixed
    {
        $user = call_user_func(Fortify::$authenticateUsingCallback, $request);

        if (! $user instanceof User) {
            $this->fireFailedEvent($request);
            $this->throwFailedAuthenticationException($request);
        }

        if (! $user->hasVerifiedEmail()) {
            $data = $this->beginVerification->execute($request, $user);

            return $request->wantsJson()
                ? response()->json(['message' => 'Verify your email before logging in.', 'data' => $data], 403)->header('Cache-Control', 'no-store')
                : to_route('verification.notice')->header('Cache-Control', 'no-store');
        }

        $this->guard->login($user, $request->boolean('remember'));

        return $next($request);
    }
}
