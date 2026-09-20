<?php

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Inertia\Testing\AssertableInertia as Assert;

test('unverified customers can access authenticated customer pages', function () {
    $customer = User::factory()->customer()->unverified()->create();

    expect($customer)->not->toBeInstanceOf(MustVerifyEmail::class);

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Customer'));
});

test('unverified administrators can access administrator pages', function () {
    $administrator = User::factory()->administrator()->unverified()->create();

    $this->actingAs($administrator)
        ->get(route('administration.products.index'))
        ->assertOk();
});

test('customers remain forbidden from administrator pages', function () {
    $customer = User::factory()->customer()->unverified()->create();

    $this->actingAs($customer)
        ->get(route('administration.products.index'))
        ->assertForbidden();
});

test('guests remain blocked from authenticated boundaries', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('administration.products.index'))->assertRedirect(route('login'));
});
