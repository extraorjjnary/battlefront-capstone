<?php

use App\Enums\AppearancePreference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('new users default to system appearance', function () {
    $user = User::factory()->create();

    expect($user->appearance)->toBe(AppearancePreference::System);
});

test('authenticated users can save their appearance preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), [
            'appearance' => AppearancePreference::Dark->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'))
        ->assertInertiaFlash('toast.message', 'Appearance updated.');

    expect($user->refresh()->appearance)->toBe(AppearancePreference::Dark);
});

test('users keep independent appearance preferences', function () {
    $darkUser = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($darkUser)
        ->patch(route('appearance.update'), ['appearance' => 'dark'])
        ->assertSessionHasNoErrors();

    $this->actingAs($otherUser)
        ->patch(route('appearance.update'), ['appearance' => 'light'])
        ->assertSessionHasNoErrors();

    expect($darkUser->refresh()->appearance)->toBe(AppearancePreference::Dark)
        ->and($otherUser->refresh()->appearance)->toBe(AppearancePreference::Light);

    $this->actingAs($otherUser)
        ->patch(route('appearance.update'), ['appearance' => 'system'])
        ->assertSessionHasNoErrors();

    expect($darkUser->refresh()->appearance)->toBe(AppearancePreference::Dark)
        ->and($otherUser->refresh()->appearance)->toBe(AppearancePreference::System);
});

test('shared appearance follows the authenticated account', function () {
    $administrator = User::factory()->administrator()->create([
        'appearance' => AppearancePreference::Dark,
    ]);
    $customer = User::factory()->customer()->create([
        'appearance' => AppearancePreference::Light,
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSee('data-appearance="dark"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'dark'));

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertSee('data-appearance="light"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'light'));
});

test('appearance persists when the same user starts a new authenticated session', function () {
    $user = User::factory()->create([
        'appearance' => AppearancePreference::Dark,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'dark'));
});

test('logout returns appearance to system', function () {
    $user = User::factory()->create([
        'appearance' => AppearancePreference::Dark,
    ]);

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();

    $this->get(route('home'))
        ->assertSee('data-appearance="system"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'system'));
});

test('guest pages use system appearance', function () {
    $this->get(route('home'))
        ->assertSee('data-appearance="system"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'system'));
});

test('appearance updates require authentication', function () {
    $this->patch(route('appearance.update'), ['appearance' => 'dark'])
        ->assertRedirectToRoute('login');
});

test('appearance updates reject unsupported values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['appearance' => 'sepia'])
        ->assertSessionHasErrors([
            'appearance' => 'Select light, dark, or system appearance.',
        ])
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->appearance)->toBe(AppearancePreference::System);
});
