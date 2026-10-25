<?php

use App\Mail\VerifyEmailOtp;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Services\EmailOtpVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

test('email OTP issuance sends a six digit code and persists only secret hashes', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create(['email' => 'customer@example.test']);

    $result = app(EmailOtpVerification::class)->start($customer, '192.0.2.1');

    Mail::assertSent(VerifyEmailOtp::class, function (VerifyEmailOtp $mail) use ($customer): bool {
        $challenge = EmailOtpChallenge::query()->where('user_id', $customer->id)->firstOrFail();
        expect($mail->code)->toMatch('/\A[0-9]{6}\z/');
        expect($challenge->code_hash)->not->toBe($mail->code);
        expect(Hash::check($mail->code, $challenge->code_hash))->toBeTrue();

        return $mail->hasTo($customer->email);
    });
    $challenge = EmailOtpChallenge::query()->sole();
    expect($challenge->context_hash)->toBe(hash('sha256', $result['verification_token']));
    expect($challenge->expires_at->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp());
    expect($result)->toMatchArray(['status' => 'verification_required', 'masked_email' => 'c***@example.test', 'attempts_remaining' => 5, 'delivery_status' => 'sent', 'retry_after' => 60]);
    expect($challenge->toArray())->not->toHaveKeys(['code_hash', 'context_hash', 'email']);
    expect($customer->refresh()->email_verified_at)->toBeNull();
});

test('a leading zero code verifies once without changing account access fields', function () {
    Mail::fake();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $challenge = EmailOtpChallenge::query()->sole();
    $challenge->code_hash = Hash::make('012345');
    $challenge->save();

    $service->verify($pending['verification_token'], '012345', '192.0.2.1');

    expect($customer->refresh()->email_verified_at)->not->toBeNull();
    expect($customer->role->value)->toBe('customer');
    expect($customer->is_active)->toBeTrue();
    $this->assertDatabaseCount('email_otp_challenges', 0);
    expect(fn () => $service->verify($pending['verification_token'], '012345', '192.0.2.1'))
        ->toThrow(ValidationException::class);
    Mail::assertSentCount(1);
});

test('invalid OTP attempts remain persisted when validation fails', function () {
    Mail::fake();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');

    expect(fn () => $service->verify($pending['verification_token'], 'invalid', '192.0.2.1'))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseHas('email_otp_challenges', ['user_id' => $customer->id, 'attempts' => 1]);
    expect($service->details($pending['verification_token'])['attempts_remaining'])->toBe(4);
    expect($customer->refresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(1);
});

test('OTPs expire exactly five minutes after issuance', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->travel(5)->minutes();

    expect(fn () => $service->verify($pending['verification_token'], $code, '192.0.2.1'))
        ->toThrow(ValidationException::class);

    expect($customer->refresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(1);
});

test('resending replaces the OTP and invalidates the previous code', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $oldCode = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->travel(60)->seconds();

    $resent = $service->resend($pending['verification_token'], '192.0.2.1');

    expect($resent['delivery_status'])->toBe('sent');
    expect(fn () => $service->verify($pending['verification_token'], $oldCode, '192.0.2.1'))
        ->toThrow(ValidationException::class);
    $newCode = Mail::sent(VerifyEmailOtp::class)->last()->code;
    expect($newCode)->not->toBe($oldCode);
    $service->verify($pending['verification_token'], $newCode, '192.0.2.1');
    expect($customer->refresh()->email_verified_at)->not->toBeNull();
    Mail::assertSentCount(2);
});

test('resending requires the entire sixty second cooldown', function () {
    Mail::fake();
    $this->freezeTime();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start(User::factory()->unverified()->create(), '192.0.2.1');
    $this->travel(59)->seconds();

    try {
        $service->resend($pending['verification_token'], '192.0.2.1');
        $this->fail('The resend cooldown must be enforced.');
    } catch (TooManyRequestsHttpException $exception) {
        expect($exception->getHeaders()['Retry-After'])->toBe(1);
    }

    Mail::assertSentCount(1);
});

test('five failed attempts exhaust an OTP even if the next code is correct', function () {
    Mail::fake();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => $service->verify($pending['verification_token'], 'wrong', '192.0.2.1'))->toThrow(ValidationException::class);
    }

    expect(fn () => $service->verify($pending['verification_token'], $code, '192.0.2.1'))->toThrow(ValidationException::class);

    $this->assertDatabaseHas('email_otp_challenges', ['user_id' => $customer->id, 'attempts' => 5]);
    expect($service->details($pending['verification_token'])['attempts_remaining'])->toBe(0);
    expect($customer->refresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(1);
});

test('restarting verification rotates its context without restoring the OTP budget', function () {
    Mail::fake();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $first = $service->start($customer, '192.0.2.1');
    expect(fn () => $service->verify($first['verification_token'], 'wrong', '192.0.2.1'))->toThrow(ValidationException::class);
    $challenge = EmailOtpChallenge::query()->sole();

    $second = $service->start($customer, '192.0.2.1');

    expect($second['verification_token'])->not->toBe($first['verification_token']);
    expect($second['attempts_remaining'])->toBe(4);
    expect($service->details($first['verification_token']))->toBeNull();
    expect(EmailOtpChallenge::query()->sole()->code_hash)->toBe($challenge->code_hash);
    expect(EmailOtpChallenge::query()->sole()->expires_at->equalTo($challenge->expires_at))->toBeTrue();
    Mail::assertSentCount(1);
});

test('expired contexts require credential confirmation again', function () {
    Mail::fake();
    $this->freezeTime();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start(User::factory()->unverified()->create(), '192.0.2.1');
    $this->travel(30)->minutes();

    expect($service->details($pending['verification_token']))->toBeNull();
    expect(fn () => $service->resend($pending['verification_token'], '192.0.2.1'))->toThrow(ValidationException::class);

    Mail::assertSentCount(1);
});

test('credential confirmation restores an expired context without resetting a still valid code', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $first = $service->start($customer, '192.0.2.1');
    expect(fn () => $service->verify($first['verification_token'], 'wrong', '192.0.2.1'))->toThrow(ValidationException::class);
    $challenge = EmailOtpChallenge::query()->sole();
    $challenge->context_expires_at = now()->subSecond();
    $challenge->save();

    $restored = $service->start($customer, '192.0.2.1');

    expect($restored['verification_token'])->not->toBe($first['verification_token']);
    expect($restored)->toMatchArray(['attempts_remaining' => 4, 'retry_after' => 60]);
    expect(EmailOtpChallenge::query()->sole()->code_hash)->toBe($challenge->code_hash);
    expect(EmailOtpChallenge::query()->sole()->expires_at->equalTo($challenge->expires_at))->toBeTrue();
    expect($service->details($first['verification_token']))->toBeNull();
    Mail::assertSentCount(1);
});

test('an exhausted OTP receives a fresh attempt budget only when a new code is issued', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => $service->verify($pending['verification_token'], 'wrong', '192.0.2.1'))->toThrow(ValidationException::class);
    }
    $restarted = $service->start($customer, '192.0.2.1');
    expect($restarted['attempts_remaining'])->toBe(0);
    $this->travel(60)->seconds();

    $resent = $service->resend($restarted['verification_token'], '192.0.2.1');

    expect($resent['attempts_remaining'])->toBe(5);
    $service->verify($restarted['verification_token'], Mail::sent(VerifyEmailOtp::class)->last()->code, '192.0.2.1');
    expect($customer->refresh()->email_verified_at)->not->toBeNull();
    Mail::assertSentCount(2);
});

test('a code cannot verify a different account or changed email', function () {
    Mail::fake();
    $first = User::factory()->unverified()->create();
    $second = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $firstPending = $service->start($first, '192.0.2.1');
    $secondPending = $service->start($second, '192.0.2.1');
    $firstChallenge = EmailOtpChallenge::query()->where('user_id', $first->id)->firstOrFail();
    $firstChallenge->code_hash = Hash::make('012345');
    $firstChallenge->save();
    $secondChallenge = EmailOtpChallenge::query()->where('user_id', $second->id)->firstOrFail();
    $secondChallenge->code_hash = Hash::make('987654');
    $secondChallenge->save();
    $first->email = 'changed@example.test';
    $first->save();

    expect(fn () => $service->verify($secondPending['verification_token'], '012345', '192.0.2.1'))->toThrow(ValidationException::class);
    expect(fn () => $service->verify($firstPending['verification_token'], '012345', '192.0.2.1'))->toThrow(ValidationException::class);

    expect($first->refresh()->email_verified_at)->toBeNull();
    expect($second->refresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(2);
});

test('inactive or already verified accounts cannot start an OTP challenge', function (bool $active, bool $verified) {
    Mail::fake();
    $customer = User::factory()->create(['is_active' => $active, 'email_verified_at' => $verified ? now() : null]);

    expect(fn () => app(EmailOtpVerification::class)->start($customer, '192.0.2.1'))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('email_otp_challenges', 0);
    Mail::assertNothingSent();
})->with(['inactive' => [false, false], 'verified' => [true, true]]);

test('deactivation after issuing an OTP prevents verification', function () {
    Mail::fake();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $customer->is_active = false;
    $customer->save();

    expect(fn () => $service->verify($pending['verification_token'], $code, '192.0.2.1'))->toThrow(ValidationException::class);

    expect($customer->refresh()->email_verified_at)->toBeNull();
    Mail::assertSentCount(1);
});

test('delivery failure invalidates the code and preserves the resend cooldown', function () {
    $mail = Mail::fake();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Sensitive SMTP details must not escape.'));
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);

    $pending = $service->start($customer, '192.0.2.1');

    expect($pending)->toMatchArray(['delivery_status' => 'failed', 'retry_after' => 60]);
    $this->assertDatabaseHas('email_otp_challenges', ['user_id' => $customer->id, 'code_hash' => null, 'expires_at' => null]);
    expect(fn () => $service->resend($pending['verification_token'], '192.0.2.1'))->toThrow(TooManyRequestsHttpException::class);
    expect($customer->refresh()->email_verified_at)->toBeNull();
    $mail->assertNothingSent();
});

test('a failed resend invalidates the previously delivered OTP', function () {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    $oldCode = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->travel(60)->seconds();
    $mail = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Delivery failed.'));

    $result = $service->resend($pending['verification_token'], '192.0.2.1');

    expect($result['delivery_status'])->toBe('failed');
    expect(fn () => $service->verify($pending['verification_token'], $oldCode, '192.0.2.1'))->toThrow(ValidationException::class);
    expect($customer->refresh()->email_verified_at)->toBeNull();
    $mail->assertSentCount(1);
});

test('logging mail transports fail closed without exposing a code', function (string $mailer) {
    Mail::fake();
    config()->set('mail.default', $mailer);

    $pending = app(EmailOtpVerification::class)->start(User::factory()->unverified()->create(), '192.0.2.1');

    expect($pending['delivery_status'])->toBe('failed');
    expect(EmailOtpChallenge::query()->sole()->code_hash)->toBeNull();
    Mail::assertNothingSent();
})->with(['log', 'failover']);

test('an account cannot issue more than five OTP emails per hour', function () {
    Mail::fake();
    $this->freezeTime();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start(User::factory()->unverified()->create(), '192.0.2.1');
    for ($issue = 1; $issue < 5; $issue++) {
        $this->travel(60)->seconds();
        $service->resend($pending['verification_token'], '192.0.2.1');
    }
    $this->travel(60)->seconds();

    expect(fn () => $service->resend($pending['verification_token'], '192.0.2.2'))->toThrow(TooManyRequestsHttpException::class);

    Mail::assertSentCount(5);
});

test('failed deliveries consume the same hourly issuance budget as successful deliveries', function () {
    $mail = Mail::fake();
    Mail::shouldReceive('to')->times(5)->andThrow(new RuntimeException('Delivery failed.'));
    $this->freezeTime();
    $customer = User::factory()->unverified()->create();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start($customer, '192.0.2.1');
    for ($issue = 1; $issue < 5; $issue++) {
        $this->travel(60)->seconds();
        $service->resend($pending['verification_token'], '192.0.2.1');
    }
    $this->travel(60)->seconds();

    $restarted = $service->start($customer, '192.0.2.2');

    expect($restarted['delivery_status'])->toBe('rate_limited');
    expect(fn () => $service->resend($restarted['verification_token'], '192.0.2.3'))->toThrow(TooManyRequestsHttpException::class);
    expect($customer->refresh()->email_verified_at)->toBeNull();
    $mail->assertNothingSent();
});

test('an IP cannot issue OTP emails for unlimited accounts', function () {
    Mail::fake();
    $service = app(EmailOtpVerification::class);
    for ($issue = 0; $issue < 20; $issue++) {
        $service->start(User::factory()->unverified()->create(), '192.0.2.1');
    }

    $limited = $service->start(User::factory()->unverified()->create(), '192.0.2.1');

    expect($limited['delivery_status'])->toBe('rate_limited');
    expect($limited['retry_after'])->toBeGreaterThan(0);
    Mail::assertSentCount(20);
});

test('verification has a shared account limit across requesting IPs', function () {
    Mail::fake();
    $service = app(EmailOtpVerification::class);
    $pending = $service->start(User::factory()->unverified()->create(), '192.0.2.1');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        expect(fn () => $service->verify($pending['verification_token'], 'wrong', '192.0.2.'.($attempt + 1)))->toThrow(ValidationException::class);
    }

    expect(fn () => $service->verify($pending['verification_token'], 'wrong', '192.0.2.100'))->toThrow(TooManyRequestsHttpException::class);

    Mail::assertSentCount(1);
});

test('verification limits an IP even when challenge tokens are invalid', function () {
    $service = app(EmailOtpVerification::class);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        expect(fn () => $service->verify(str_repeat('a', 64), '012345', '192.0.2.1'))->toThrow(ValidationException::class);
    }

    expect(fn () => $service->verify(str_repeat('b', 64), '012345', '192.0.2.1'))->toThrow(TooManyRequestsHttpException::class);
});

test('verification email explains direct code entry and never includes a verification link', function () {
    $mail = new VerifyEmailOtp('012345');

    $mail->assertSeeInHtml('012345');
    $mail->assertSeeInText('5 minutes');
    $mail->assertSeeInText('Never share this code');
    $mail->assertSeeInText('Battlefront Computer Trading');
    $mail->assertDontSeeInHtml('/email/verify/');
});
