<?php

use App\Mail\VerifyEmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('mobile email verification allows a subsequent login without issuing a premature token', function () {
    Mail::fake();
    $registration = $this->postJson('/api/v1/auth/register', [
        'name' => 'Mobile Customer',
        'email' => 'mobile@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertCreated()->assertJsonMissingPath('data.token');
    $user = User::query()->where('email', 'mobile@example.test')->sole();
    $mail = Mail::sent(VerifyEmailOtp::class)->sole();
    $context = $registration->json('data.verification_token');

    $this->postJson('/api/v1/auth/email/verify', [
        'verification_token' => $context,
        'code' => $mail->code,
    ])->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonMissingPath('data.token');

    expect($user->refresh()->email_verified_at)->not->toBeNull();
    $this->assertGuest();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.token_type', 'Bearer');

    Mail::assertSent(VerifyEmailOtp::class, fn (VerifyEmailOtp $sent): bool => $sent->hasTo($user->email));
});

test('valid credentials for an existing unverified mobile customer provide verification without a token', function () {
    Mail::fake();
    $user = User::factory()->customer()->unverified()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertForbidden()->assertJsonPath('data.status', 'verification_required')
        ->assertJsonPath('data.delivery_status', 'sent')->assertJsonMissingPath('data.token');

    expect($user->refresh()->email_verified_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSent(VerifyEmailOtp::class, fn (VerifyEmailOtp $mail): bool => $mail->hasTo($user->email));
});

test('inactive unverified mobile customers receive generic credential errors without mail', function () {
    Mail::fake();
    $user = User::factory()->customer()->unverified()->create(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertNothingSent();
});

test('old unverified bearer tokens cannot access protected or optional authenticated mobile features', function (string $endpoint) {
    $user = User::factory()->customer()->unverified()->create();
    $token = $user->createToken('Before verification enforcement')->plainTextToken;

    $this->withToken($token)->getJson($endpoint)->assertUnauthorized();

    expect($user->refresh()->email_verified_at)->toBeNull();
})->with(['/api/v1/profile', '/api/v1/cart', '/api/v1/recommendations']);

test('old unverified bearer tokens cannot access customer chatbot context', function () {
    $user = User::factory()->customer()->unverified()->create();
    $token = $user->createToken('Before verification enforcement')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/chatbot', ['message' => 'Where is my order?'])->assertUnauthorized();
});

test('mobile verification rejects invalid codes without verifying the account', function () {
    Mail::fake();
    $user = User::factory()->customer()->unverified()->create();
    $pending = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertForbidden();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $invalidCode = ($code[0] === '0' ? '1' : '0').substr($code, 1);

    $this->postJson('/api/v1/auth/email/verify', [
        'verification_token' => $pending->json('data.verification_token'),
        'code' => $invalidCode,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect($user->refresh()->email_verified_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSentCount(1);
});

test('mobile resend respects cooldown and sends a replacement after sixty seconds', function () {
    Mail::fake();
    $user = User::factory()->customer()->unverified()->create();
    $pending = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertForbidden();
    $context = $pending->json('data.verification_token');

    $this->postJson('/api/v1/auth/email/resend', ['verification_token' => $context])
        ->assertTooManyRequests()->assertHeader('Retry-After');
    Mail::assertSentCount(1);

    $this->travel(60)->seconds();

    $this->postJson('/api/v1/auth/email/resend', ['verification_token' => $context])
        ->assertOk()->assertJsonPath('data.delivery_status', 'sent')->assertJsonMissingPath('data.token');

    Mail::assertSentCount(2);
    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('mobile verification endpoints return generic field errors for unknown contexts', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/email/verify', [
        'verification_token' => str_repeat('a', 64),
        'code' => '001234',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/auth/email/resend', ['verification_token' => str_repeat('b', 64)])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    Mail::assertNothingSent();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('mobile registration preserves an unverified account when email delivery fails', function () {
    Mail::shouldReceive('to')->once()->with('undelivered@example.test')
        ->andThrow(new RuntimeException('Mail delivery unavailable.'));

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Mobile Customer',
        'email' => 'undelivered@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertCreated()->assertJsonPath('data.delivery_status', 'failed')
        ->assertJsonPath('data.expires_at', null)->assertJsonMissingPath('data.token');

    $this->assertDatabaseHas('users', ['email' => 'undelivered@example.test', 'email_verified_at' => null]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertGuest();
});

test('mobile resend returns 503 without verifying the account when mail delivery fails', function () {
    Mail::fake();
    $user = User::factory()->customer()->unverified()->create();
    $pending = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertForbidden();
    Mail::assertSentCount(1);
    $this->travel(60)->seconds();
    Mail::shouldReceive('to')->once()->with($user->email)
        ->andThrow(new RuntimeException('Mail delivery unavailable.'));

    $this->postJson('/api/v1/auth/email/resend', [
        'verification_token' => $pending->json('data.verification_token'),
    ])->assertStatus(503)->assertJsonPath('data.delivery_status', 'failed')
        ->assertJsonPath('data.expires_at', null)->assertJsonMissingPath('data.token');

    expect($user->refresh()->email_verified_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('mobile verification validates its context and preserves the six-digit string contract', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/email/verify', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['verification_token', 'code']);
    $this->postJson('/api/v1/auth/email/resend', [])
        ->assertUnprocessable()->assertJsonValidationErrors('verification_token');
    $this->postJson('/api/v1/auth/email/verify', [
        'verification_token' => str_repeat('a', 64),
        'code' => 123456,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    Mail::assertNothingSent();
});
