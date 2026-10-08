<?php

use App\Models\GuestRecommendationProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate without a verified email', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))->assertOk();
});

test('successful sign-in merges guest recommendation history and expires its browser cookie', function () {
    $user = User::factory()->customer()->create();
    $token = 'opaque-test-guest-profile-token';
    $profile = GuestRecommendationProfile::factory()->create([
        'token_hash' => hash('sha256', $token),
    ]);
    $search = $profile->searches()->create([
        'query' => 'graphics card',
        'expires_at' => now()->addDays(90),
    ]);

    $response = $this->withCookie('battlefront_recommendation_profile', $token)
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertCookieExpired('battlefront_recommendation_profile');
    expect($search->refresh()->user_id)->toBe($user->id)
        ->and($search->guest_recommendation_profile_id)->toBeNull();
    $this->assertModelMissing($profile);
});

test('database sessions retain nullable unconstrained user references', function () {
    DB::table('sessions')->insert([
        [
            'id' => 'guest-session',
            'user_id' => null,
            'payload' => 'guest-payload',
            'last_activity' => 1,
        ],
        [
            'id' => 'missing-user-session',
            'user_id' => PHP_INT_MAX,
            'payload' => 'missing-user-payload',
            'last_activity' => 1,
        ],
    ]);

    $this->assertDatabaseHas('sessions', [
        'id' => 'guest-session',
        'user_id' => null,
    ]);
    $this->assertDatabaseHas('sessions', [
        'id' => 'missing-user-session',
        'user_id' => PHP_INT_MAX,
    ]);
});

test('two factor factory state can be built while the feature is disabled', function () {
    $user = User::factory()->withTwoFactor()->make();

    expect(Features::enabled(Features::twoFactorAuthentication()))->toBeFalse()
        ->and($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_recovery_codes)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));
    $response->assertCookieNotExpired('battlefront_recommendation_profile');

    $this->assertGuest();
    $this->assertDatabaseCount('guest_recommendation_profiles', 1);
});

test('logging out and signing into another account keeps the first account history isolated', function () {
    $firstCustomer = User::factory()->customer()->create();
    $secondCustomer = User::factory()->customer()->create();
    $oldToken = 'first-account-guest-profile-token';
    $oldProfile = GuestRecommendationProfile::factory()->create([
        'token_hash' => hash('sha256', $oldToken),
    ]);
    $search = $oldProfile->searches()->create([
        'query' => 'gaming laptop',
        'expires_at' => now()->addDays(90),
    ]);

    $logoutResponse = $this->actingAs($firstCustomer)
        ->withCookie('battlefront_recommendation_profile', $oldToken)
        ->post(route('logout'));
    $newGuestCookie = $logoutResponse->getCookie('battlefront_recommendation_profile', decrypt: false);

    $response = $this->withUnencryptedCookie('battlefront_recommendation_profile', $newGuestCookie->getValue())
        ->post(route('login.store'), [
            'email' => $secondCustomer->email,
            'password' => 'password',
        ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    expect($search->refresh()->user_id)->toBe($firstCustomer->id);
    $this->assertDatabaseMissing('customer_searches', [
        'user_id' => $secondCustomer->id,
        'query' => 'gaming laptop',
    ]);
    $this->assertModelMissing($oldProfile);
    $this->assertDatabaseCount('guest_recommendation_profiles', 0);
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
