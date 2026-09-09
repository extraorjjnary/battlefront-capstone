<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::where('email', 'test@example.com')->firstOrFail()->role)
        ->toBe(UserRole::Customer);
});

test('new customers receive an email verification notification', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $customer = User::where('email', 'customer@example.com')->firstOrFail();

    Notification::assertSentTo($customer, VerifyEmail::class);
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
