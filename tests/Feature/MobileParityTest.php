<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

it('updates a customer password using the guarded mobile endpoint', function () {
    $customer = User::factory()->customer()->create();
    $token = $customer->createToken('mobile')->plainTextToken;
    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'New-secure-password',
        'password_confirmation' => 'New-secure-password',
    ])->assertOk()->assertJsonPath('message', 'Password updated.');
    expect(Hash::check('New-secure-password', $customer->fresh()->password))->toBeTrue();
});

it('returns 422 without changing the mobile customer password when the shared policy is not met', function () {
    $customer = User::factory()->customer()->create();
    $originalPasswordHash = $customer->password;
    $token = $customer->createToken('mobile')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'password' => 'The password field must contain at least one uppercase and one lowercase letter.',
    ]);

    expect($customer->fresh()->password)->toBe($originalPasswordHash);
});

it('requires a valid customer token to change a password', function () {
    $this->putJson('/api/v1/profile/password', [])->assertUnauthorized();
    $admin = User::factory()->create(['role' => UserRole::Administrator]);
    $this->withToken($admin->createToken('mobile')->plainTextToken)->putJson('/api/v1/profile/password', [])->assertForbidden();
});

it('keeps wishlist records private to the signed-in customer', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $token = $customer->createToken('mobile')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();
    $this->withToken($token)->getJson('/api/v1/wishlist')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.product_id', $product->id);
    $other = User::factory()->customer()->create();
    Auth::forgetGuards();
    $this->withToken($other->createToken('mobile')->plainTextToken)->getJson('/api/v1/wishlist')->assertJsonPath('count', 0);
    Auth::forgetGuards();
    $this->withToken($token)->deleteJson('/api/v1/wishlist/'.$product->id)->assertOk();
});

it('records recommendation dwell for an authenticated customer product view', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create(['is_featured' => true]);
    $token = $customer->createToken('mobile')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/products/'.$product->id)->assertOk();
    $this->withToken($token)->postJson('/api/v1/products/'.$product->id.'/dwell', ['seconds' => 8])->assertNoContent();
    expect($customer->productViews()->whereBelongsTo($product)->exists())->toBeTrue();
});
