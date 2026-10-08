<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('profile returns 401 for missing, invalid, and revoked bearer tokens', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8');
    $token->accessToken->delete();

    $this->get('/api/v1/profile')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withToken('invalid-token')->get('/api/v1/profile')->assertUnauthorized();
    $this->withToken($token->plainTextToken)->get('/api/v1/profile')->assertUnauthorized();
});

test('a web session alone cannot access the mobile profile', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)->get('/api/v1/profile')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a customer sees only their own four approved profile fields', function () {
    $user = User::factory()->customer()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);
    User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8')->plainTextToken;

    $this->withToken($token)->get('/api/v1/profile')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'default_delivery_address' => '12 Mabini Street, Sagay City',
            'search_recommendations_enabled' => true,
            'product_view_recommendations_enabled' => true,
        ]]);
});

test('a customer can update only their own approved profile fields', function () {
    $user = User::factory()->customer()->create();
    $otherUser = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Updated Customer',
        'email' => 'updated@example.com',
        'default_delivery_address' => '45 Rizal Avenue, Escalante City',
        'search_recommendations_enabled' => true,
        'product_view_recommendations_enabled' => true,
        'role' => UserRole::Administrator->value,
        'appearance' => 'dark',
        'password' => 'changed-password',
        'user_id' => $otherUser->id,
    ])->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.com',
            'default_delivery_address' => '45 Rizal Avenue, Escalante City',
            'search_recommendations_enabled' => true,
            'product_view_recommendations_enabled' => true,
        ]]);

    $user->refresh();
    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->appearance->value)->toBe('system')
        ->and($user->email_verified_at)->toBeNull();
    expect(Hash::check('password', $user->password))->toBeTrue();
    $this->assertDatabaseHas('users', [
        'id' => $otherUser->id,
        'email' => $otherUser->email,
    ]);
});

test('profile update returns 422 for a duplicate email and leaves the profile unchanged', function () {
    $user = User::factory()->customer()->create();
    $otherUser = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Updated Customer',
        'email' => $otherUser->email,
    ])->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    expect($user->refresh()->name)->not->toBe('Updated Customer');
});

test('an administrator bearer token receives 403 on customer profile routes', function () {
    $administrator = User::factory()->administrator()->create();
    $token = $administrator->createToken('Pixel 8')->plainTextToken;

    $this->withToken($token)->get('/api/v1/profile')
        ->assertForbidden()->assertJsonStructure(['message']);
    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Changed Administrator',
        'email' => $administrator->email,
    ])->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertForbidden();

    expect($administrator->refresh()->name)->not->toBe('Changed Administrator');
});

test('an expired token receives 401 on the profile route', function () {
    $user = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8', ['*'], now()->subMinute())->plainTextToken;

    $this->withToken($token)->get('/api/v1/profile')->assertUnauthorized();
});
