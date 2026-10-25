<?php

use App\Enums\UserRole;
use App\Mail\VerifyEmailOtp;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Services\EmailOtpVerification;
use App\Services\TeamAccessService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

test('unverified customer sessions cannot access authenticated customer pages', function () {
    $customer = User::factory()->customer()->unverified()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertRedirectToRoute('login');

    $this->assertGuest();
});

test('unverified administrator sessions cannot access administrator pages', function () {
    $administrator = User::factory()->administrator()->unverified()->create();

    $this->actingAs($administrator)
        ->get(route('administration.products.index'))
        ->assertRedirectToRoute('login');

    $this->assertGuest();
});

test('customers remain forbidden from administrator pages', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.products.index'))
        ->assertForbidden();
});

test('old unverified remember cookies cannot restore authenticated access', function () {
    $customer = User::factory()->customer()->unverified()->create();
    $guard = Auth::guard('web');
    $cookieName = $guard->getRecallerName();
    $cookieValue = $customer->id.'|'.$customer->remember_token.'|'.$guard->hashPasswordForCookie($customer->password);

    $this->withCookie($cookieName, $cookieValue)->get(route('dashboard'))
        ->assertRedirectToRoute('login')
        ->assertCookieExpired($cookieName);

    $this->assertGuest();
});

test('verification page requires pending context and does not send mail on GET', function () {
    Mail::fake();
    $customer = User::factory()->customer()->unverified()->create();
    $verification = app(EmailOtpVerification::class)->start($customer, '127.0.0.1');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;

    $this->withSession(['email_verification.token' => $verification['verification_token']])
        ->get(route('verification.notice'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/VerifyEmail')
            ->where('auth.user', null)
            ->where('verification.status', 'verification_required')
            ->where('verification.attempts_remaining', 5)
            ->missing('verification.verification_token')
            ->missing('verification.code')
            ->missing('verification_token'))
        ->assertDontSee($code);

    $this->get(route('verification.notice'))->assertOk();
    Mail::assertSentCount(1);
    $this->assertGuest();
});

test('correct web OTP verifies email once and requires subsequent login', function () {
    Mail::fake();
    $customer = User::factory()->customer()->unverified()->create();
    $verification = app(EmailOtpVerification::class)->start($customer, '127.0.0.1');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;

    $this->withSession(['email_verification.token' => $verification['verification_token']])
        ->post(route('verification.verify'), ['code' => $code])
        ->assertRedirectToRoute('login')
        ->assertSessionHas('status')
        ->assertSessionMissing('email_verification.token');

    expect($customer->fresh()->email_verified_at)->not->toBeNull();
    $this->assertGuest();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->withSession(['email_verification.token' => $verification['verification_token']])
        ->post(route('verification.verify'), ['code' => $code])->assertRedirectToRoute('login');
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($customer);
    Mail::assertSentCount(1);
});

test('registration verification never signs in with an invalid expired or exhausted code', function (string $state) {
    Mail::fake();
    $this->freezeTime();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'new-customer@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'new-customer@example.test')->sole();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $wrongCode = $code === '000000' ? '000001' : '000000';
    if ($state === 'expired') {
        $this->travel(5)->minutes();
    } elseif ($state === 'exhausted') {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('verification.notice'))->post(route('verification.verify'), ['code' => $wrongCode])
                ->assertSessionHasErrors('code');
        }
    }

    $this->from(route('verification.notice'))->post(route('verification.verify'), ['code' => $state === 'invalid' ? $wrongCode : $code])
        ->assertRedirectToRoute('verification.notice')->assertSessionHasErrors('code');

    $this->assertGuest();
    expect($customer->fresh()->email_verified_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSentCount(1);
})->with(['invalid', 'expired', 'exhausted']);

test('registration resend retains automatic sign in eligibility for the replacement code', function () {
    Mail::fake();
    $this->freezeTime();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'resend-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'resend-registration@example.test')->sole();
    $this->travel(60)->seconds();
    $this->post(route('verification.send'))->assertRedirectToRoute('verification.notice');

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->last()->code])
        ->assertRedirectToRoute('dashboard')->assertSessionMissing('email_verification');

    $this->assertAuthenticatedAs($customer);
    expect($customer->fresh()->email_verified_at)->not->toBeNull();
    Mail::assertSentCount(2);
});

test('returning through login clears registration automatic sign in eligibility', function () {
    Mail::fake();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'recovered-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'recovered-registration@example.test')->sole();
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('verification.notice')->assertSessionMissing('email_verification.registration');

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('login');

    $this->assertGuest();
    expect($customer->fresh()->email_verified_at)->not->toBeNull();
    Mail::assertSentCount(1);
});

test('replacing a registration context cannot automatically sign in its verified account', function (bool $differentAccount) {
    Mail::fake();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'original-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $registeredCustomer = User::query()->where('email', 'original-registration@example.test')->sole();
    $target = $differentAccount ? User::factory()->customer()->unverified()->create() : $registeredCustomer;
    $replacement = app(EmailOtpVerification::class)->start($target, '127.0.0.1');

    $this->withSession(['email_verification.token' => $replacement['verification_token']])
        ->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->last()->code])
        ->assertRedirectToRoute('login')->assertSessionMissing('email_verification');

    $this->assertGuest();
    expect($target->fresh()->email_verified_at)->not->toBeNull();
    if ($differentAccount) {
        expect($registeredCustomer->fresh()->email_verified_at)->toBeNull();
    }
    Mail::assertSentCount($differentAccount ? 2 : 1);
})->with(['same account new context' => false, 'different account context' => true]);

test('changed registration credentials or role prevent automatic sign in after ownership verification', function (Closure $changeAccount) {
    Mail::fake();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'changed-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'changed-registration@example.test')->sole();
    $changeAccount($customer);

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('login')->assertSessionMissing('email_verification');

    $this->assertGuest();
    expect($customer->fresh()->email_verified_at)->not->toBeNull();
    Mail::assertSentCount(1);
})->with([
    'password changed' => [fn (User $customer) => $customer->forceFill(['password' => 'replacement-password'])->save()],
    'administrator role assigned' => [fn (User $customer) => $customer->forceFill(['role' => UserRole::Administrator])->save()],
]);

test('deactivated registration accounts cannot verify or automatically sign in', function () {
    Mail::fake();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'inactive-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'inactive-registration@example.test')->sole();
    $customer->forceFill(['is_active' => false])->save();

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('login');

    $this->assertGuest();
    expect($customer->fresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(1);
});

test('consumed registration verification cannot restore authentication after logout', function () {
    Mail::fake();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'consumed-registration@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $pending = session('email_verification');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->post(route('verification.verify'), ['code' => $code])->assertRedirectToRoute('dashboard');
    $this->assertAuthenticated();
    $this->post(route('logout'));

    $this->withSession(['email_verification' => $pending])
        ->post(route('verification.verify'), ['code' => $code])->assertRedirectToRoute('login');

    $this->assertGuest();
    $this->assertDatabaseCount('email_otp_challenges', 0);
    Mail::assertSentCount(1);
});

test('web verification rejects wrong and expired codes with actionable errors', function (bool $expired) {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->customer()->unverified()->create();
    $verification = app(EmailOtpVerification::class)->start($customer, '127.0.0.1');
    $issuedCode = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $code = $expired ? $issuedCode : ($issuedCode === '000000' ? '000001' : '000000');
    if ($expired) {
        $this->travel(5)->minutes();
    }

    $this->withSession(['email_verification.token' => $verification['verification_token']])
        ->from(route('verification.notice'))->post(route('verification.verify'), ['code' => $code])
        ->assertRedirectToRoute('verification.notice')
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('_old_input.code');

    expect($customer->fresh()->email_verified_at)->toBeNull();
    $this->assertGuest();
    Mail::assertSentCount(1);
})->with(['invalid' => false, 'expired' => true]);

test('web resend preserves cooldown and replaces the previous code', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->customer()->unverified()->create();
    $verification = app(EmailOtpVerification::class)->start($customer, '127.0.0.1');
    $previousCode = Mail::sent(VerifyEmailOtp::class)->sole()->code;

    $this->withSession(['email_verification.token' => $verification['verification_token']])
        ->from(route('verification.notice'))->post(route('verification.send'))
        ->assertRedirectToRoute('verification.notice')->assertSessionHasErrors('code');
    Mail::assertSentCount(1);

    $this->travel(60)->seconds();
    $this->post(route('verification.send'))->assertRedirectToRoute('verification.notice');
    $this->post(route('verification.verify'), ['code' => $previousCode])->assertSessionHasErrors('code');
    expect($customer->fresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(2);
});

test('verification and resend without valid context reveal no account information', function (string $context) {
    Mail::fake();
    $customer = User::factory()->customer()->unverified()->create();

    $this->withSession(['email_verification.token' => $context])
        ->post(route('verification.verify'), ['email' => $customer->email, 'user_id' => $customer->id, 'code' => '123456'])
        ->assertRedirectToRoute('login')->assertSessionHas('status');
    $this->withSession(['email_verification.token' => $context])
        ->post(route('verification.send'), ['email' => $customer->email])
        ->assertRedirectToRoute('login')->assertSessionHas('status');
    $this->get(route('verification.notice'))->assertRedirectToRoute('login');

    expect($customer->fresh()->email_verified_at)->toBeNull();
    Mail::assertNothingSent();
})->with(['missing' => '', 'unknown' => str_repeat('a', 64)]);

test('another accounts OTP cannot verify the current pending account', function () {
    Mail::fake();
    $firstCustomer = User::factory()->customer()->unverified()->create();
    $secondCustomer = User::factory()->customer()->unverified()->create();
    $first = app(EmailOtpVerification::class)->start($firstCustomer, '127.0.0.1');
    $second = app(EmailOtpVerification::class)->start($secondCustomer, '127.0.0.1');
    $secondCode = Mail::sent(VerifyEmailOtp::class)->last()->code;
    EmailOtpChallenge::query()->where('user_id', $firstCustomer->id)->update(['code_hash' => Hash::make($secondCode === '000000' ? '000001' : '000000')]);

    $this->withSession(['email_verification.token' => $first['verification_token']])
        ->from(route('verification.notice'))->post(route('verification.verify'), [
            'code' => $secondCode,
            'user_id' => $secondCustomer->id,
            'verification_token' => $second['verification_token'],
        ])->assertSessionHasErrors('code');

    expect($firstCustomer->fresh()->email_verified_at)->toBeNull();
    expect($secondCustomer->fresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(2);
});

test('returning login reuses an active code without resetting its attempts or expiry', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->customer()->unverified()->create();
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('verification.notice');
    $challenge = EmailOtpChallenge::query()->where('user_id', $customer->id)->sole();
    $expiresAt = $challenge->expires_at->toIso8601String();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->from(route('verification.notice'))->post(route('verification.verify'), [
        'code' => $code === '000000' ? '000001' : '000000',
    ])->assertSessionHasErrors('code');

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('verification.notice');
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->where('verification.attempts_remaining', 4)
        ->where('verification.expires_at', $expiresAt));

    $this->assertGuest();
    Mail::assertSentCount(1);
});

test('registration delivery failures retain an unverified account and show safe recovery feedback', function () {
    Mail::shouldReceive('to')->once()->with('mail-failure@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->with(Mockery::type(VerifyEmailOtp::class))
        ->andThrow(new RuntimeException('private-mail-error-sentinel'));

    $this->post(route('register.store'), [
        'name' => 'Mail Failure Customer',
        'email' => 'mail-failure@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $customer = User::query()->where('email', 'mail-failure@example.test')->sole();

    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->where('verification.delivery_status', 'failed')
        ->where('verification.expires_at', null))
        ->assertDontSee('private-mail-error-sentinel');

    expect($customer->email_verified_at)->toBeNull();
    $this->assertGuest();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('web verification preserves the hourly issuance wait across redirects without claiming delivery failure', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->customer()->unverified()->create();
    $quotaExpiresAt = now()->addHour()->toIso8601String();
    $verification = app(EmailOtpVerification::class)->start($customer, '127.0.0.1');
    for ($issued = 1; $issued < 5; $issued++) {
        $this->travel(60)->seconds();
        app(EmailOtpVerification::class)->resend($verification['verification_token'], '127.0.0.1');
    }
    $this->travel(5)->minutes();

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('verification.notice');
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->where('verification.delivery_status', 'rate_limited')
        ->where('verification.resend_available_at', $quotaExpiresAt));
    $this->post(route('verification.send'))->assertRedirectToRoute('verification.notice')->assertSessionHasErrors('code');
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->where('verification.delivery_status', 'rate_limited')
        ->where('verification.resend_available_at', $quotaExpiresAt));

    Mail::assertSentCount(5);
    $this->assertGuest();
    expect($customer->fresh()->email_verified_at)->toBeNull();
});

test('new team administrators verify ownership before their first login without changing access assignments', function () {
    Mail::fake();
    $owner = User::factory()->administrator()->create();
    $administrator = app(TeamAccessService::class)->create($owner, [
        'name' => 'New Staff',
        'email' => 'new.staff@example.test',
        'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1',
    ]);
    $roleId = $administrator->administration_role_id;

    $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'CorrectHorseBatteryStaple!1'])
        ->assertRedirectToRoute('verification.notice');
    $this->assertGuest();
    expect($administrator->fresh()->email_verified_at)->toBeNull();

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('login');
    $administrator->refresh();
    expect($administrator->administration_role_id)->toBe($roleId)
        ->and($administrator->administrationRoleCode())->toBe('staff')
        ->and($administrator->is_active)->toBeTrue();
    $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'CorrectHorseBatteryStaple!1'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($administrator);
    Mail::assertSentCount(1);
});

test('guests remain blocked from authenticated boundaries', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('administration.products.index'))->assertRedirect(route('login'));
});
