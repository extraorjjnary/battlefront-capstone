<?php

use Inertia\Testing\AssertableInertia as Assert;

test('guests can view the Battlefront homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user', null)
            ->etc());
});
