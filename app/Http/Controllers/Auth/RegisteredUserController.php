<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\BeginEmailVerification;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;
use LogicException;

class RegisteredUserController extends FortifyRegisteredUserController
{
    public function __construct(StatefulGuard $guard)
    {
        parent::__construct($guard);
        $this->middleware('throttle:registration')->only('store');
    }

    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        if (config('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([Fortify::username() => Str::lower($request->string(Fortify::username())->toString())]);
        }

        $user = $creator->create($request->all());
        if (! $user instanceof User) {
            throw new LogicException('Customer registration must create a Battlefront account.');
        }
        event(new Registered($user));

        $profile = $request->attributes->get('guest_recommendation_profile');
        if ($profile instanceof GuestRecommendationProfile) {
            $request->session()->put('registration_continuation', [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
                'token_hash' => $profile->token_hash,
            ]);
        }

        $verification = app(BeginEmailVerification::class)->execute($request, $user);
        $request->session()->put('email_verification.registration', [
            'user_id' => $user->id,
            'email' => $user->email,
            'context_hash' => hash('sha256', $verification['verification_token']),
            'password_fingerprint' => hash_hmac('sha256', $user->getAuthPassword(), (string) config('app.key')),
        ]);

        return app(RegisterResponse::class);
    }
}
