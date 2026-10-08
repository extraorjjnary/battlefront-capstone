<?php

namespace App\Http\Middleware;

use App\Actions\Recommendation\MergeGuestRecommendationHistory;
use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveGuestRecommendationProfile
{
    public function __construct(private readonly MergeGuestRecommendationHistory $mergeHistory) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUserAtStart = $request->user();
        $wasAuthenticated = $authenticatedUserAtStart !== null;
        $profile = null;
        $token = null;

        if (! $wasAuthenticated) {
            $token = $request->cookie('battlefront_recommendation_profile');

            if (is_string($token) && $token !== '') {
                $profile = GuestRecommendationProfile::query()
                    ->where('token_hash', hash('sha256', $token))
                    ->first();

                if ($profile !== null && $profile->expires_at->isPast()) {
                    $profile->delete();
                    $profile = null;
                }
            }

            if ($profile === null) {
                $token = Str::random(64);
                $profile = GuestRecommendationProfile::query()->create([
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDays(90),
                ]);
            } else {
                $profile->expires_at = now()->addDays(90);
                $profile->save();
            }

            $request->attributes->set('guest_recommendation_profile', $profile);
            $request->attributes->set('guest_recommendation_token', $token);
        } else {
            $token = $request->cookie('battlefront_recommendation_profile');

            if (is_string($token) && $token !== '') {
                $profile = GuestRecommendationProfile::query()
                    ->where('token_hash', hash('sha256', $token))
                    ->first();

                if ($profile !== null && $profile->expires_at->isPast()) {
                    $profile->delete();
                    $profile = null;
                }
            }
        }

        $response = $next($request);
        $user = $request->user();
        $isLoggingOut = $wasAuthenticated && $user === null && $request->routeIs('logout');
        $profileOwner = $user instanceof User
            ? $user
            : ($isLoggingOut && $authenticatedUserAtStart instanceof User ? $authenticatedUserAtStart : null);

        if ($profile !== null && $profileOwner instanceof User) {
            if ($profileOwner->role === UserRole::Customer) {
                ($this->mergeHistory)($profile, $profileOwner);
            } else {
                $profile->delete();

                if (! $isLoggingOut) {
                    return $response->withCookie(Cookie::forget('battlefront_recommendation_profile'));
                }
            }

            if (! $isLoggingOut) {
                return $response->withCookie(Cookie::forget('battlefront_recommendation_profile'));
            }
        }

        if ($isLoggingOut) {
            $token = Str::random(64);
            $profile = GuestRecommendationProfile::query()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(90),
            ]);
        }

        if ($wasAuthenticated && $profile === null && $token !== null && ! $isLoggingOut) {
            return $response->withCookie(Cookie::forget('battlefront_recommendation_profile'));
        }

        if ($profile !== null && $token !== null) {
            $minutes = 90 * 24 * 60;

            return $response->withCookie(Cookie::make(
                'battlefront_recommendation_profile',
                $token,
                $minutes,
                '/',
                config('session.domain'),
                $request->isSecure() || (bool) config('session.secure'),
                true,
                false,
                'lax',
            ));
        }

        return $response;
    }
}
