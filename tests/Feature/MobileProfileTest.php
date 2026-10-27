<?php

use App\Enums\UserRole;
use App\Models\CustomerSearch;
use App\Models\Inventory;
use App\Models\Product;
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

test('a customer sees only their own profile fields and recommendation preferences', function () {
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
            'appearance' => 'light',
            'default_delivery_address' => '12 Mabini Street, Sagay City',
            'default_delivery_address_prefill' => [
                'delivery_destination' => 'Sagay City', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
            ],
            'default_delivery_contact_number' => null,
            'search_recommendations_enabled' => true,
            'product_view_recommendations_enabled' => true,
            'personalized_recommendations_enabled' => true,
        ]]);
});

test('a customer can update only their own approved profile fields', function () {
    $user = User::factory()->customer()->create();
    $otherUser = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8')->plainTextToken;
    $verifiedAt = $user->getRawOriginal('email_verified_at');

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Updated Customer',
        'email' => $user->email,
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
            'email' => $user->email,
            'appearance' => 'dark',
            'default_delivery_address' => '45 Rizal Avenue, Escalante City',
            'default_delivery_address_prefill' => [
                'delivery_destination' => 'Escalante City', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
            ],
            'default_delivery_contact_number' => null,
            'search_recommendations_enabled' => true,
            'product_view_recommendations_enabled' => true,
            'personalized_recommendations_enabled' => true,
        ]]);

    $user->refresh();
    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->appearance->value)->toBe('dark')
        ->and($user->getRawOriginal('email_verified_at'))->toBe($verifiedAt);
    expect(Hash::check('password', $user->password))->toBeTrue();
    $this->assertDatabaseHas('users', [
        'id' => $otherUser->id,
        'email' => $otherUser->email,
    ]);
});

test('mobile customers recover retained behavioral recommendations after re-enabling personalization', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'name' => 'RTX 5090 Graphics Card',
        'is_featured' => false,
    ]);
    Inventory::factory()->for($product)->create(['quantity' => 8, 'reorder_level' => 2]);
    $search = CustomerSearch::factory()->for($customer)->create([
        'query' => 'RTX 5090',
        'expires_at' => now()->addDays(90),
    ]);
    $token = $customer->createToken('Pixel 8')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => $customer->name,
        'email' => $customer->email,
        'personalized_recommendations_enabled' => false,
    ])->assertOk();
    $this->assertModelExists($search);
    $this->withToken($token)->getJson('/api/v1/recommendations/personalized')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => $customer->name,
        'email' => $customer->email,
        'personalized_recommendations_enabled' => true,
    ])->assertOk();

    $this->withToken($token)->getJson('/api/v1/recommendations/personalized')
        ->assertOk()
        ->assertJsonPath('data.0.product.id', $product->id)
        ->assertJsonPath('data.0.reasons.0.code', 'matched_recent_searches');
});

test('profile update returns 422 for a duplicate email and leaves the profile unchanged', function () {
    $user = User::factory()->customer()->create();
    $otherUser = User::factory()->customer()->create();
    $token = $user->createToken('Pixel 8')->plainTextToken;
    $original = $user->refresh()->getAttributes();

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Updated Customer',
        'email' => $otherUser->email,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Your account email address cannot be changed.']);

    expect($user->fresh()->getAttributes())->toBe($original);
});

test('mobile name updates preserve verification status and require a verified account', function (bool $verified) {
    $user = User::factory()->customer()->create([
        'email_verified_at' => $verified ? '2026-01-01 00:00:00' : null,
        'default_delivery_address' => 'Saved address',
    ]);
    $token = $user->createToken('Pixel 8')->plainTextToken;
    $verifiedAt = $user->getRawOriginal('email_verified_at');

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Updated Customer',
        'email_verified_at' => '2026-02-01 00:00:00',
    ])->assertStatus($verified ? 200 : 401);

    if (! $verified) {
        expect($user->refresh()->name)->not->toBe('Updated Customer')
            ->and($user->email_verified_at)->toBeNull();

        return;
    }

    $this->getJson('/api/v1/profile')->assertOk()
        ->assertJsonPath('data.name', 'Updated Customer')
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.default_delivery_address', 'Saved address');

    expect($user->refresh()->name)->toBe('Updated Customer')
        ->and($user->getRawOriginal('email_verified_at'))->toBe($verifiedAt);
})->with(['verified' => true, 'unverified' => false]);

test('mobile email replacements return 422 without saving profile changes', function (mixed $email) {
    $user = User::factory()->customer()->create(['email' => 'customer@example.test']);
    $token = $user->createToken('Pixel 8')->plainTextToken;
    $original = $user->refresh()->getAttributes();

    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => 'Changed name',
        'email' => $email,
        'default_delivery_address' => 'Changed address',
        'personalized_recommendations_enabled' => false,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Your account email address cannot be changed.']);

    expect($user->fresh()->getAttributes())->toBe($original);
})->with([
    'new address' => 'replacement@example.test',
    'case change' => 'CUSTOMER@example.test',
    'blank' => '',
    'null' => null,
    'array' => [['customer@example.test']],
    'number' => 123,
    'invalid address' => 'invalid',
]);

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
