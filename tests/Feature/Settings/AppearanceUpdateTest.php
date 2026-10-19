<?php

use App\Enums\AppearancePreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('new users default to light appearance', function () {
    $user = User::factory()->create();

    expect($user->appearance)->toBe(AppearancePreference::Light);

    $id = DB::table('users')->insertGetId(User::factory()->raw());

    $this->assertDatabaseHas('users', ['id' => $id, 'appearance' => 'light']);
});

test('authenticated users can save their appearance preference', function (AppearancePreference $appearance) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), [
            'appearance' => $appearance->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'))
        ->assertInertiaFlash('toast.message', 'Appearance updated.');

    expect($user->refresh()->appearance)->toBe($appearance);
})->with([AppearancePreference::Light, AppearancePreference::Dark]);

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

test('appearance persists when the same user starts a new authenticated session', function (AppearancePreference $appearance) {
    $user = User::factory()->create([
        'appearance' => $appearance,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', $appearance->value));
})->with([AppearancePreference::Light, AppearancePreference::Dark]);

test('logout returns appearance to light', function () {
    $user = User::factory()->create([
        'appearance' => AppearancePreference::Dark,
    ]);

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();

    $this->get(route('home'))
        ->assertSee('data-appearance="light"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'light'));
});

test('guest pages use light appearance', function () {
    $this->get(route('home'))
        ->assertSee('data-appearance="light"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance', 'light'));
});

test('appearance updates require authentication', function () {
    $this->patch(route('appearance.update'), ['appearance' => 'dark'])
        ->assertRedirectToRoute('login');
});

test('appearance updates reject unsupported values', function (mixed $appearance) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['appearance' => $appearance])
        ->assertSessionHasErrors([
            'appearance' => 'Select light or dark appearance.',
        ])
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->appearance)->toBe(AppearancePreference::Light);
})->with(['system', 'sepia', 123]);

test('legacy system appearance displays as light without changing the stored preference', function () {
    $user = User::factory()->create(['appearance' => AppearancePreference::System]);

    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertSee('data-appearance="light"', escape: false)
        ->assertDontSee('prefers-color-scheme')
        ->assertInertia(fn (Assert $page) => $page->where('appearance', 'light'));

    $this->assertDatabaseHas('users', ['id' => $user->id, 'appearance' => 'system']);
});

test('appearance default migration changes future inserts without rewriting existing preferences', function () {
    $migration = require database_path('migrations/2026_10_19_045635_set_light_default_for_user_appearance.php');
    $users = collect([AppearancePreference::Light, AppearancePreference::Dark, AppearancePreference::System])
        ->map(fn (AppearancePreference $appearance) => User::factory()->create(['appearance' => $appearance]));

    $migration->down();
    $legacyId = DB::table('users')->insertGetId(User::factory()->raw());
    $this->assertDatabaseHas('users', ['id' => $legacyId, 'appearance' => 'system']);

    $migration->up();
    $newId = DB::table('users')->insertGetId(User::factory()->raw());
    $this->assertDatabaseHas('users', ['id' => $newId, 'appearance' => 'light']);
    $this->assertDatabaseHas('users', ['id' => $legacyId, 'appearance' => 'system']);

    foreach ($users as $user) {
        $this->assertDatabaseHas('users', ['id' => $user->id, 'appearance' => $user->appearance->value]);
    }
});
