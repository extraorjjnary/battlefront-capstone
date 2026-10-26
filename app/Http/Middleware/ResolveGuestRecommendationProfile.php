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
        $cookie = $request->cookie('battlefront_recommendation_profile');
        $token = is_string($cookie) ? $cookie : null;
        $routeName = $request->route()?->getName();
        $profileRoutes = ['home', 'products.index', 'products.show'];
        $profileLookupRoutes = [...$profileRoutes, 'products.dwell.store', 'products.view.store'];
        $authenticationRoutes = ['login.store', 'register.store', 'verification.verify'];
        $shouldResolveProfile = in_array($routeName, $profileLookupRoutes, true)
            || (is_string($token) && $token !== '' && in_array($routeName, $authenticationRoutes, true));

        if (! $wasAuthenticated && $shouldResolveProfile) {
            if (is_string($token) && $token !== '') {
                $profile = GuestRecommendationProfile::query()
                    ->where('token_hash', hash('sha256', $token))
                    ->first();

                if ($profile !== null && $profile->expires_at->isPast()) {
                    $profile->delete();
                    $profile = null;
                }
            }

            if ($profile === null && in_array($routeName, $profileRoutes, true)) {
                $token = Str::random(64);
                $profile = GuestRecommendationProfile::query()->create([
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDays(90),
                ]);
            } elseif ($profile !== null && ($profile->updated_at === null || $profile->updated_at->lte(now()->subMinutes(15)))) {
                $profile->expires_at = now()->addDays(90);
                $profile->save();
            }

            if ($profile !== null) {
                $request->attributes->set('guest_recommendation_profile', $profile);
                $request->attributes->set('guest_recommendation_token', $token);
            }
        } elseif ($wasAuthenticated) {
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
        $continuation = $user instanceof User && $request->routeIs('login.store', 'verification.verify')
            ? $request->session()->pull('registration_continuation') : null;
        $isVerifiedRegistrationLogin = ! $wasAuthenticated
            && $request->routeIs('login.store', 'verification.verify')
            && $user instanceof User
            && $user->hasVerifiedEmail()
            && is_array($continuation)
            && ($continuation['user_id'] ?? null) === $user->id
            && ($continuation['profile_id'] ?? null) === $profile?->id
            && is_string($continuation['token_hash'] ?? null)
            && hash_equals($continuation['token_hash'], $profile->token_hash ?? '');

        if ($profile !== null && ($user instanceof User || $isLoggingOut)) {
            if ($isVerifiedRegistrationLogin && $user->role === UserRole::Customer) {
                ($this->mergeHistory)($profile, $user);
            } else {
                $profile->delete();
            }

            if (! $isLoggingOut) {
                $response->headers->setCookie(Cookie::forget('battlefront_recommendation_profile'));

                return $response;
            }
        }

        if ($user instanceof User && in_array($routeName, $authenticationRoutes, true)) {
            $response->headers->setCookie(Cookie::forget('battlefront_recommendation_profile'));

            return $response;
        }

        if ($isLoggingOut) {
            $token = Str::random(64);
            $profile = GuestRecommendationProfile::query()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(90),
            ]);
        }

        if ($wasAuthenticated && $profile === null && $token !== null && ! $isLoggingOut) {
            $response->headers->setCookie(Cookie::forget('battlefront_recommendation_profile'));

            return $response;
        }

        if ($profile !== null && $token !== null) {
            $minutes = 90 * 24 * 60;

            $response->headers->setCookie(Cookie::make(
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

            return $response;
        }

        return $response;
    }
}
