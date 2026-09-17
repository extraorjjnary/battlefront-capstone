<?php

use App\Enums\UserRole;
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
