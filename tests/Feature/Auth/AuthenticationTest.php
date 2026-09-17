<?php

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

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
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

    $this->assertGuest();
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
