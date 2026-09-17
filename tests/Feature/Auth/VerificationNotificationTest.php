<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

test('registration does not send an email verification notification', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $customer = User::where('email', 'customer@example.com')->firstOrFail();

    Notification::assertNotSentTo($customer, VerifyEmail::class);
});

test('email verification routes are not registered', function () {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse()
        ->and(Route::has('verification.send'))->toBeFalse();
});
