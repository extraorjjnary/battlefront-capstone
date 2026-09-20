<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('users receive only their own saved sidebar state', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $this->withUnencryptedCookies([
        'sidebar_state_'.$administrator->id => 'false',
        'sidebar_state_'.$customer->id => 'true',
        'sidebar_state_'.$otherCustomer->id => 'false',
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', false));

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', true));

    $this->actingAs($otherCustomer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', false));
});

test('the same user restores their saved sidebar state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('sidebar_state_'.$user->id, 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', false));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', false));
});

test('users without a saved sidebar state default to open', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('sidebar_state_'.$otherUser->id, 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', true));
});

test('guests do not reuse authenticated sidebar state', function () {
    $user = User::factory()->create();

    $this->withUnencryptedCookies([
        'sidebar_state' => 'false',
        'sidebar_state_'.$user->id => 'false',
    ])->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', true));
});
