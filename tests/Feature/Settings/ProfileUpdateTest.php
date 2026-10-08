<?php

use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/Profile')
        ->where('canManageDefaultDeliveryAddress', true)
        ->where('defaultDeliveryAddress', '12 Mabini Street, Sagay City')
        ->where('searchRecommendationsEnabled', true));
});

test('administrator profile excludes the default delivery address', function () {
    $administrator = User::factory()->administrator()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);

    $this->actingAs($administrator)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('canManageDefaultDeliveryAddress', false)
            ->where('defaultDeliveryAddress', null));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('default delivery address can be saved and updated', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'default_delivery_address' => '12 Mabini Street, Sagay City',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->default_delivery_address)
        ->toBe('12 Mabini Street, Sagay City');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'default_delivery_address' => '45 Rizal Avenue, Escalante City',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->default_delivery_address)
        ->toBe('45 Rizal Avenue, Escalante City');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('customers cannot change their role through profile updates', function () {
    $user = User::factory()->customer()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::Administrator->value,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->role)->toBe(UserRole::Customer);
});

test('administrators cannot save a default delivery address', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->patch(route('profile.update'), [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'default_delivery_address' => '12 Mabini Street, Sagay City',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($administrator->refresh()->default_delivery_address)->toBeNull();
});

test('customer account deletion is unavailable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    $this->assertModelExists($user);
});
