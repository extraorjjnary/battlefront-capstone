<?php

use App\Enums\UserRole;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
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
        ->and($user->appearance->value)->toBe('light')
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
    $view = $profile->productViews()->create([
        'product_id' => Product::factory()->create()->id,
        'dwell_seconds' => 120,
        'expires_at' => now()->addDay(),
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
    expect($view->refresh()->user_id)->toBe($customer->id)
        ->and($view->guest_recommendation_profile_id)->toBeNull()
        ->and($view->dwell_seconds)->toBe(120);
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

test('customer registration still requires a valid unique email', function (array $emailInput) {
    User::factory()->customer()->create(['email' => 'taken@example.test']);

    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$emailInput,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
})->with([
    'missing' => [[]],
    'invalid' => [['email' => 'invalid']],
    'duplicate' => [['email' => 'taken@example.test']],
]);

test('administrator account creation still requires a valid unique email', function (array $emailInput) {
    $owner = User::factory()->administrator()->create(['email' => 'owner@example.test']);

    $this->actingAs($owner)->post(route('administration.team.store'), [
        'name' => 'New Staff',
        'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
        ...$emailInput,
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseCount('users', 1);
})->with([
    'missing' => [[]],
    'invalid' => [['email' => 'invalid']],
    'duplicate' => [['email' => 'owner@example.test']],
]);
