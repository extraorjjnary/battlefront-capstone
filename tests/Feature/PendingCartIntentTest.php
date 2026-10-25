<?php

use App\Mail\VerifyEmailOtp;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Mail;

dataset('unavailable pending cart products', [
    'inactive product' => [fn (Product $product) => $product->update(['is_active' => false]), 'product_id', 'This product is no longer available.'],
    'inactive category' => [fn (Product $product) => $product->category()->update(['is_active' => false]), 'product_id', 'This product is no longer available.'],
    'missing inventory' => [fn (Product $product) => $product->inventory()->delete(), 'product_id', 'Stock information is unavailable for this product.'],
    'out of stock' => [fn (Product $product) => $product->inventory()->update(['quantity' => 0]), 'quantity', 'This product is out of stock.'],
    'insufficient stock' => [fn (Product $product) => $product->inventory()->update(['quantity' => 1]), 'quantity', 'Only 1 item(s) are available.'],
]);

test('guest selection is stored briefly in the session without creating a cart', function () {
    $this->freezeTime();
    $product = Product::factory()->available()->create();

    $response = $this->post(route('cart.intent.store'), [
        'product_id' => $product->id,
        'quantity' => 3,
        'expires_at' => now()->addYear()->getTimestamp(),
        'token' => 'client-token',
    ]);

    $response->assertRedirectToRoute('login')
        ->assertSessionHas('pending_cart_intent.product_id', $product->id)
        ->assertSessionHas('pending_cart_intent.quantity', 3)
        ->assertSessionHas('pending_cart_intent.expires_at', now()->addMinutes(15)->getTimestamp());
    expect(session('pending_cart_intent.token'))->toBeUuid();
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
    expect($product->inventory->quantity)->toBe(10);
});

test('the latest guest selection replaces the previous pending selection', function () {
    $firstProduct = Product::factory()->available()->create();
    $secondProduct = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $firstProduct->id, 'quantity' => 2]);

    $this->post(route('cart.intent.store'), ['product_id' => $secondProduct->id, 'quantity' => 4])
        ->assertRedirectToRoute('login')
        ->assertSessionHas('pending_cart_intent.product_id', $secondProduct->id)
        ->assertSessionHas('pending_cart_intent.quantity', 4);

    $this->assertDatabaseCount('carts', 0);
});

test('guest selection rejects products that are currently unavailable', function (Closure $changeProduct, string $field, string $message) {
    $product = Product::factory()->available()->create();
    $changeProduct($product);

    $this->from(route('products.show', $product))
        ->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2])
        ->assertRedirectToRoute('products.show', $product)
        ->assertSessionHasErrors([$field => $message])
        ->assertSessionMissing('pending_cart_intent');

    $this->assertDatabaseCount('carts', 0);
})->with('unavailable pending cart products');

test('guest selection validates quantity before preserving an intent', function (mixed $quantity, string $message) {
    $product = Product::factory()->available()->create();

    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => $quantity])
        ->assertSessionHasErrors(['quantity' => $message])
        ->assertSessionMissing('pending_cart_intent');

    $this->assertDatabaseCount('carts', 0);
})->with([
    'missing quantity' => [null, 'Enter a quantity.'],
    'zero' => [0, 'Quantity must be at least 1.'],
    'negative' => [-1, 'Quantity must be at least 1.'],
    'fractional' => [1.5, 'Quantity must be a whole number.'],
    'too large' => [4294967296, 'Quantity is too large.'],
]);

test('guest selection validates product identity', function (mixed $productId, string $message) {
    $this->post(route('cart.intent.store'), ['product_id' => $productId, 'quantity' => 1])
        ->assertSessionHasErrors(['product_id' => $message])
        ->assertSessionMissing('pending_cart_intent');

    $this->assertDatabaseCount('carts', 0);
})->with([
    'missing' => [null, 'Select a product to add.'],
    'invalid' => ['invalid', 'Select a valid product.'],
    'not found' => [999, 'Select an available product.'],
]);

test('authenticated accounts cannot create a guest cart intent', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($user)->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2])
        ->assertRedirectToRoute('dashboard')
        ->assertSessionMissing('pending_cart_intent');

    $this->assertDatabaseCount('carts', 0);
})->with(['customer', 'administrator']);

test('customer login adds the pending quantity to the existing cart and redirects with success', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $item = (new CartService)->add($customer, $product->id, 2);
    $otherItem = (new CartService)->add($otherCustomer, $product->id, 1);
    $this->withSession(['url.intended' => route('products.index')])
        ->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 3]);
    $this->get(route('login'))->assertOk();

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product added to cart.'])
        ->assertSessionMissing('pending_cart_intent')
        ->assertSessionMissing('url.intended');

    $this->assertAuthenticatedAs($customer);
    expect($item->refresh()->quantity)->toBe(5);
    expect($otherItem->refresh()->quantity)->toBe(1);
    expect($product->inventory->quantity)->toBe(10);
    $this->get(route('cart.index'))->assertOk();
    $this->get(route('cart.index'))->assertOk();
    expect($item->refresh()->quantity)->toBe(5);
});

test('registration preserves a pending selection until verification signs the new customer in', function () {
    Mail::fake();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 4]);
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'pending-cart@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice')
        ->assertSessionHas('pending_cart_intent.quantity', 4);

    $this->assertGuest();
    $this->assertDatabaseCount('carts', 0);
    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product added to cart.'])
        ->assertSessionMissing('pending_cart_intent');

    $customer = User::query()->where('email', 'pending-cart@example.com')->sole();
    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('carts', ['user_id' => $customer->id]);
    $this->assertDatabaseHas('cart_items', ['cart_id' => $customer->cart->id, 'product_id' => $product->id, 'quantity' => 4]);
    expect($product->inventory->quantity)->toBe(10);
    Mail::assertSentCount(1);
});

test('failed authentication retains the intent for a successful retry', function (string $routeName, string $errorField) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);

    $this->post(route($routeName), ['email' => $customer->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors($errorField)
        ->assertSessionHas('pending_cart_intent.quantity', 2);

    $this->assertGuest();
    $this->assertDatabaseCount('carts', 0);
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index');
    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
})->with([
    'login' => ['login.store', 'email'],
    'registration' => ['register.store', 'name'],
]);

test('expired intent is discarded and preserves normal authentication redirects', function (string $routeName) {
    Mail::fake();
    $this->freezeTime();
    $customer = User::factory()->customer()->make();
    if ($routeName === 'login.store') {
        $customer->save();
    }
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->travel(15)->minutes();

    $response = $this->withSession(['url.intended' => route('products.index')])->post(route($routeName), [
        'name' => $customer->name,
        'email' => $customer->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    if ($routeName === 'register.store') {
        $response->assertRedirectToRoute('verification.notice');
        $response = $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code]);
    }
    $response->assertRedirectToRoute('products.index')
        ->assertSessionMissing('pending_cart_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertDatabaseCount('carts', 0);
    Mail::assertSentCount($routeName === 'register.store' ? 1 : 0);
})->with(['login.store', 'register.store']);

test('malformed session intents are discarded without interrupting customer login', function (array $invalidFields) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $intent = array_replace(session('pending_cart_intent'), $invalidFields);

    $this->withSession(['pending_cart_intent' => $intent])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_cart_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseCount('carts', 0);
})->with([
    'invalid product' => [['product_id' => 'invalid']],
    'nonpositive quantity' => [['quantity' => 0]],
    'invalid expiry' => [['expires_at' => null]],
    'invalid token' => [['token' => 'invalid']],
]);

test('normal JSON login responses are unchanged without a cart intent', function () {
    $customer = User::factory()->customer()->create();

    $this->postJson(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertOk()->assertExactJson(['two_factor' => false]);

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseCount('carts', 0);
});

test('JSON registration returns verification status without authentication or a cart', function () {
    Mail::fake();
    $this->postJson(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'normal-json@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated()->assertJsonPath('data.status', 'verification_required')
        ->assertJsonMissingPath('data.registration')
        ->assertJsonMissingPath('data.password_fingerprint')
        ->assertJsonMissingPath('email_verification');

    $this->assertGuest();
    $this->assertDatabaseCount('carts', 0);
    Mail::assertSentCount(1);
});

test('administrator login discards the pending selection without using a customer cart', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);

    $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_cart_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertAuthenticatedAs($administrator);
    $this->assertDatabaseCount('carts', 0);
    $this->get(route('cart.index'))->assertForbidden();
});

test('login gracefully consumes products that became unavailable during authentication', function (Closure $changeProduct, string $field, string $message) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $changeProduct($product);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message])
        ->assertSessionMissing('pending_cart_intent');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
})->with('unavailable pending cart products');

test('deleted pending products do not interrupt successful registration', function () {
    Mail::fake();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $product->inventory()->delete();
    $product->delete();

    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'deleted-product@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice');
    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This product is no longer available.'])
        ->assertSessionMissing('pending_cart_intent');

    $this->assertAuthenticated();
    $this->assertDatabaseCount('carts', 0);
    Mail::assertSentCount(1);
});

test('pending quantity cannot push an existing cart line beyond current stock', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $item = (new CartService)->add($customer, $product->id, 9);
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only 10 item(s) are available.'])
        ->assertSessionMissing('pending_cart_intent');

    expect($item->refresh()->quantity)->toBe(9);
    $this->assertDatabaseCount('cart_items', 1);
});

test('a consumed intent cannot add again from a stale authentication session snapshot', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $intent = session('pending_cart_intent');
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index');
    $this->post(route('logout'));

    $this->withSession(['pending_cart_intent' => $intent])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_cart_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseCount('cart_items', 1);
});

test('normal authentication retains intended redirects without an intent', function (string $routeName) {
    Mail::fake();
    $customer = User::factory()->customer()->make();
    if ($routeName === 'login.store') {
        $customer->save();
    }

    $response = $this->withSession(['url.intended' => route('products.index')])->post(route($routeName), [
        'name' => $customer->name,
        'email' => $customer->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    if ($routeName === 'register.store') {
        $response->assertRedirectToRoute('verification.notice');
        $response = $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code]);
    }
    $response->assertRedirectToRoute('products.index')->assertInertiaFlashMissing('toast');

    $this->assertDatabaseCount('carts', 0);
    Mail::assertSentCount($routeName === 'register.store' ? 1 : 0);
})->with(['login.store', 'register.store']);
