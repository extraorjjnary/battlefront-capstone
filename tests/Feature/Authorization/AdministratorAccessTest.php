<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/test/administrator-only', fn () => response()->noContent())
        ->middleware(['web', 'auth', 'can:access-administration']);
});

test('guests are redirected to login', function () {
    $response = $this->get('/test/administrator-only');

    $response->assertRedirect(route('login'));
});

test('customers are forbidden from administrator routes', function () {
    $customer = User::factory()->customer()->create();

    $response = $this
        ->actingAs($customer)
        ->get('/test/administrator-only');

    $response->assertForbidden();
});

test('administrators can access administrator routes', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this
        ->actingAs($administrator)
        ->get('/test/administrator-only');

    $response->assertNoContent();
});
