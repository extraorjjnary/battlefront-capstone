<?php

use App\Enums\UserRole;
use App\Mail\VerifyEmailOtp;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('storefront activity does not consume registration attempts', function (string $registrationRoute) {
    Mail::fake();
    $product = Product::factory()->available()->create();

    for ($view = 0; $view < 5; $view++) {
        $this->postJson(route('products.view.store', $product))->assertNoContent();
    }

    $this->postJson(route($registrationRoute), [
        'name' => 'New Customer',
        'email' => 'after-browsing@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Registration test',
    ])->assertCreated()->assertJsonPath('data.status', 'verification_required');

    $this->assertGuest();
    Mail::assertSentCount(1);
})->with(['web' => 'register.store', 'mobile' => 'api.v1.auth.register']);

test('web and mobile registration share a five per minute limit isolated by IP', function () {
    Mail::fake();
    $this->freezeTime();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route($attempt % 2 === 0 ? 'register.store' : 'api.v1.auth.register'), [])
            ->assertUnprocessable();
    }

    $this->postJson(route('register.store'), [])->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->postJson(route('api.v1.auth.register'), [])->assertTooManyRequests()->assertHeader('Retry-After', '60');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.45'])
        ->postJson(route('register.store'), [])->assertUnprocessable();
    $this->travel(60)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
        ->postJson(route('register.store'), [])->assertUnprocessable();

    $this->assertDatabaseCount('users', 0);
    Mail::assertNothingSent();
});

test('new customers sign in only after verifying email in their registration session', function () {
    Mail::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'remember' => true,
    ]);

    $this->assertGuest();
    $response->assertRedirectToRoute('verification.notice')
        ->assertSessionHas('email_verification.token');

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->appearance->value)->toBe('light')
        ->and($user->email_verified_at)->toBeNull();

    $this->get(route('dashboard'))->assertRedirectToRoute('login');
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->missing('verification.registration')
        ->missing('registration')
        ->missing('email_verification'));
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSent(VerifyEmailOtp::class, fn (VerifyEmailOtp $mail): bool => $mail->hasTo($user->email));

    $sessionId = session()->getId();
    $csrfToken = session()->token();
    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('dashboard')
        ->assertSessionMissing('email_verification')
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($csrfToken);
    $this->get(route('dashboard'))->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSentCount(1);
});

test('new customers inherit guest recommendation history when verification signs them in', function (bool $returnThroughLogin) {
    Mail::fake();
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

    $response->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'new-customer@example.com')->firstOrFail();
    expect($search->refresh()->user_id)->toBeNull();
    expect($view->refresh()->user_id)->toBeNull();
    $this->assertModelExists($profile);
    $this->assertGuest();

    if ($returnThroughLogin) {
        $this->withCookie('battlefront_recommendation_profile', $token)
            ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirectToRoute('verification.notice');
        $this->assertGuest();
    }

    $this->withCookie('battlefront_recommendation_profile', $token)
        ->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('dashboard')
        ->assertSessionMissing('registration_continuation')
        ->assertCookieExpired('battlefront_recommendation_profile');

    $this->assertAuthenticatedAs($customer);
    expect($search->refresh()->user_id)->toBe($customer->id)
        ->and($search->guest_recommendation_profile_id)->toBeNull();
    expect($view->refresh()->user_id)->toBe($customer->id)
        ->and($view->guest_recommendation_profile_id)->toBeNull()
        ->and($view->dwell_seconds)->toBe(120);
    $this->assertModelMissing($profile);
    Mail::assertSentCount(1);
})->with(['immediate verification' => false, 'verification after login' => true]);

test('registration cannot create an administrator', function () {
    Mail::fake();
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => UserRole::Administrator->value,
    ]);

    $this->assertGuest();
    $response->assertRedirectToRoute('verification.notice');

    expect(User::where('email', 'customer@example.com')->firstOrFail()->role)
        ->toBe(UserRole::Customer);
    Mail::assertSentCount(1);
});

test('registration continuation cannot transfer guest history to another account', function () {
    Mail::fake();
    $otherCustomer = User::factory()->customer()->create();
    $token = 'registration-continuation-guest-token';
    $profile = GuestRecommendationProfile::factory()->create(['token_hash' => hash('sha256', $token)]);
    $search = $profile->searches()->create(['query' => 'gaming laptop', 'expires_at' => now()->addDay()]);

    $this->withCookie('battlefront_recommendation_profile', $token)->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'continuation@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');

    $this->withCookie('battlefront_recommendation_profile', $token)
        ->post(route('login.store'), ['email' => $otherCustomer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($otherCustomer);
    $this->assertDatabaseMissing('customer_searches', ['id' => $search->id]);
    $this->assertModelMissing($profile);
    Mail::assertSentCount(1);
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
