<?php

use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new customers can register and immediately access authenticated pages', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->email_verified_at)->toBeNull();

    $this->get(route('dashboard'))->assertOk();
});

test('new customers inherit guest recommendation history when they register', function () {
    $token = 'new-customer-guest-profile-token';
    $profile = GuestRecommendationProfile::factory()->create([
        'token_hash' => hash('sha256', $token),
    ]);
    $search = $profile->searches()->create([
        'query' => 'graphics card',
        'expires_at' => now()->addDays(90),
    ]);

    $response = $this->withCookie('battlefront_recommendation_profile', $token)
        ->post(route('register.store'), [
            'name' => 'New Customer',
            'email' => 'new-customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertCookieExpired('battlefront_recommendation_profile');
    $customer = User::query()->where('email', 'new-customer@example.com')->firstOrFail();
    expect($search->refresh()->user_id)->toBe($customer->id)
        ->and($search->guest_recommendation_profile_id)->toBeNull();
    $this->assertModelMissing($profile);
});

test('registration cannot create an administrator', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => UserRole::Administrator->value,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::where('email', 'customer@example.com')->firstOrFail()->role)
        ->toBe(UserRole::Customer);
});
