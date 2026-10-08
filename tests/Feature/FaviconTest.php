<?php

use App\Models\User;

test('the public storefront advertises the Battlefront favicon and fallbacks', function () {
    $response = $this->get(route('home'));

    $response->assertSee('href="/favicon.svg?v=battlefront-b" sizes="any" type="image/svg+xml"', false)
        ->assertSee('href="/favicon.ico?v=battlefront-b" sizes="16x16 32x32" type="image/x-icon"', false)
        ->assertSee('href="/apple-touch-icon.png?v=battlefront-b" sizes="180x180"', false)
        ->assertDontSee('href="/favicon.svg"', false)
        ->assertDontSee('href="/favicon.ico"', false)
        ->assertDontSee('href="/apple-touch-icon.png"', false);
});

test('customer and administration dashboards advertise the same Battlefront icons', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSee('href="/favicon.svg?v=battlefront-b" sizes="any" type="image/svg+xml"', false)
        ->assertSee('href="/favicon.ico?v=battlefront-b" sizes="16x16 32x32" type="image/x-icon"', false)
        ->assertSee('href="/apple-touch-icon.png?v=battlefront-b" sizes="180x180"', false);
})->with(['customer', 'administrator']);
