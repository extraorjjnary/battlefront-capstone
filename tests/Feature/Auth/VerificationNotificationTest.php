<?php

use App\Mail\VerifyEmailOtp;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

test('registration does not send an email verification notification', function () {
    Notification::fake();
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $customer = User::where('email', 'customer@example.com')->firstOrFail();

    Notification::assertNotSentTo($customer, VerifyEmail::class);
    Mail::assertSent(VerifyEmailOtp::class, fn (VerifyEmailOtp $mail): bool => $mail->hasTo($customer->email));
});

test('email verification routes accept codes instead of signed links', function () {
    expect(Route::has('verification.notice'))->toBeTrue()
        ->and(Route::has('verification.verify'))->toBeTrue()
        ->and(Route::has('verification.send'))->toBeTrue();

    $this->get('/email/verify/1/old-link-signature')->assertNotFound();
});
