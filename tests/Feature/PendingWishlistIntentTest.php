<?php

use App\Mail\VerifyEmailOtp;
use App\Models\Product;
use App\Models\User;
use App\Services\Wishlist\WishlistService;
use Illuminate\Support\Facades\Mail;

test('guest hearts preserve only a server controlled short lived selection without saving wishlist data', function () {
    $this->freezeTime();
    $product = Product::factory()->available()->create();
    $otherCustomer = User::factory()->customer()->create();

    $this->post(route('wishlist.intent.store'), [
        'product_id' => $product->id,
        'user_id' => $otherCustomer->id,
        'expires_at' => now()->addYear()->getTimestamp(),
        'token' => 'client-token',
    ])->assertRedirectToRoute('login')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id)
        ->assertSessionHas('pending_wishlist_intent.expires_at', now()->addMinutes(15)->getTimestamp())
        ->assertSessionMissing('pending_wishlist_intent.user_id');

    expect(session('pending_wishlist_intent.token'))->toBeUuid();
    $this->assertGuest();
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 0);
});

test('the latest wishlist selection replaces only the wishlist intent', function () {
    $firstProduct = Product::factory()->available()->create();
    $secondProduct = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $firstProduct->id, 'quantity' => 2]);
    $cartIntent = session('pending_cart_intent');
    $this->post(route('wishlist.intent.store'), ['product_id' => $firstProduct->id]);

    $this->post(route('wishlist.intent.store'), ['product_id' => $secondProduct->id])
        ->assertRedirectToRoute('login')
        ->assertSessionHas('pending_wishlist_intent.product_id', $secondProduct->id)
        ->assertSessionHas('pending_cart_intent', $cartIntent);

    $wishlistIntent = session('pending_wishlist_intent');
    $this->post(route('cart.intent.store'), ['product_id' => $secondProduct->id, 'quantity' => 1])
        ->assertSessionHas('pending_wishlist_intent', $wishlistIntent);
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('invalid product selections never create an intent', function (mixed $productId) {
    $this->post(route('wishlist.intent.store'), ['product_id' => $productId])
        ->assertSessionHasErrors('product_id')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 0);
})->with(['missing' => null, 'zero' => 0, 'negative' => -1, 'fractional' => 1.5, 'invalid' => 'invalid', 'not found' => 999]);

test('authenticated accounts cannot create guest wishlist intents', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($user)->post(route('wishlist.intent.store'), ['product_id' => $product->id])
        ->assertRedirectToRoute('dashboard')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
})->with(['customer', 'administrator']);

test('successful login saves to the authenticated customer and redirects to their wishlist with fresh counts', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id, 'user_id' => $otherCustomer->id]);

    $this->withSession(['url.intended' => route('products.index')])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.'])
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertSessionMissing('url.intended');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
    $this->assertDatabaseMissing('wishlist_items', ['user_id' => $otherCustomer->id]);
    $this->assertDatabaseCount('wishlist_activities', 1);
    $this->get(route('wishlist.index'))->assertInertia(fn ($page) => $page
        ->component('Wishlist/Index')
        ->where('wishlistCount', 1)
        ->where('wishlist.items.data.0.product_id', $product->id));
});

test('registration waits for successful OTP before saving and signing the customer in', function (bool $returnThroughLogin) {
    Mail::fake();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'pending-wishlist@example.com',
        'password' => 'RegistrationPass!',
        'password_confirmation' => 'RegistrationPass!',
    ])->assertRedirectToRoute('verification.notice')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id);

    if ($returnThroughLogin) {
        $this->post(route('login.store'), ['email' => 'pending-wishlist@example.com', 'password' => 'RegistrationPass!'])
            ->assertRedirectToRoute('verification.notice');
    }

    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->post(route('verification.verify'), ['code' => $code === '000000' ? '999999' : '000000'])
        ->assertSessionHasErrors('code')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id);
    $this->assertGuest();
    $this->assertDatabaseCount('wishlist_items', 0);

    $this->post(route('verification.verify'), ['code' => $code])
        ->assertRedirectToRoute('wishlist.index')
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.']);

    $customer = User::query()->where('email', 'pending-wishlist@example.com')->sole();
    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
    $this->assertDatabaseCount('wishlist_activities', 1);
    Mail::assertSentCount(1);
})->with(['immediate verification' => false, 'return through login' => true]);

test('credential confirmed login for an unverified customer resumes only after OTP verification', function () {
    Mail::fake();
    $customer = User::factory()->customer()->unverified()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('verification.notice')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id);
    $this->assertGuest();
    $this->assertDatabaseCount('wishlist_items', 0);

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirectToRoute('wishlist.index')
        ->assertSessionMissing('pending_wishlist_intent');
    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
    Mail::assertSentCount(1);
});

test('failed login keeps the selection for a successful retry', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id);
    $this->assertGuest();
    $this->assertDatabaseCount('wishlist_items', 0);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index');
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
});

test('already saved selections do not duplicate wishlist entries or activity', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    (new WishlistService)->add($customer, $product->id);
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product is already in your wishlist.'])
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 1);
    $this->assertDatabaseCount('wishlist_activities', 1);
});

test('expired selections preserve the normal intended redirect', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $this->travel(15)->minutes();

    $this->withSession(['url.intended' => route('products.index')])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('products.index')
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('invalid server side intent data is discarded without changing normal login', function (mixed $changes) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $intent = is_array($changes) ? array_replace(session('pending_wishlist_intent'), $changes) : $changes;

    $this->withSession(['pending_wishlist_intent' => $intent])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 0);
})->with([
    'non array' => 'invalid',
    'missing product' => [['product_id' => null]],
    'non integer product' => [['product_id' => '1']],
    'non positive product' => [['product_id' => 0]],
    'missing expiry' => [['expires_at' => null]],
    'invalid expiry' => [['expires_at' => 'invalid']],
    'missing token' => [['token' => null]],
    'invalid token' => [['token' => 'invalid']],
]);

test('unavailable or deleted selections are consumed with an error after successful login', function (Closure $changeProduct) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $changeProduct($product);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This product cannot be saved right now.'])
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 0);
})->with([
    'inactive product' => [fn (Product $product) => $product->update(['is_active' => false])],
    'inactive category' => [fn (Product $product) => $product->category()->update(['is_active' => false])],
    'deleted category' => [fn (Product $product) => $product->category->delete()],
    'deleted product' => [fn (Product $product) => $product->delete()],
    'permanently deleted product' => [function (Product $product): void {
        $product->inventory()->delete();
        $product->forceDelete();
    }],
]);

test('out of stock products can still be saved using existing wishlist eligibility', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $product->inventory()->update(['quantity' => 0]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.']);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
});

test('administrator login discards the selection and prevents a stale replay under a customer', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $intent = session('pending_wishlist_intent');

    $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlashMissing('toast');
    $this->assertAuthenticatedAs($administrator);
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->post(route('logout'));

    $this->withSession(['pending_wishlist_intent' => $intent])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertInertiaFlashMissing('toast');
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 0);
});

test('a consumed wishlist selection cannot be replayed under the same or another customer', function (bool $switchAccount) {
    $customer = User::factory()->customer()->create();
    $nextCustomer = $switchAccount ? User::factory()->customer()->create() : $customer;
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $intent = session('pending_wishlist_intent');
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index');
    $this->delete(route('wishlist.destroy', $product->id));
    $this->post(route('logout'));

    $this->withSession(['pending_wishlist_intent' => $intent])
        ->post(route('login.store'), ['email' => $nextCustomer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 2);
})->with(['same customer' => false, 'another customer' => true]);

test('cart and wishlist intents resume independently even with matching replay tokens', function () {
    $customer = User::factory()->customer()->create();
    $cartProduct = Product::factory()->available()->create();
    $wishlistProduct = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $cartProduct->id, 'quantity' => 2]);
    $this->post(route('wishlist.intent.store'), ['product_id' => $wishlistProduct->id]);

    $this->withSession(['pending_wishlist_intent.token' => session('pending_cart_intent.token')])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertSessionMissing('pending_cart_intent')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseHas('cart_items', ['product_id' => $cartProduct->id, 'quantity' => 2]);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $wishlistProduct->id]);
    $this->assertDatabaseCount('wishlist_activities', 1);
});

test('an expired wishlist intent does not interfere with a pending cart', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);

    $this->withSession(['pending_wishlist_intent.expires_at' => 0])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product added to cart.'])
        ->assertSessionMissing('pending_cart_intent')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('an unavailable pending cart product does not prevent a wishlist save', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('cart.intent.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $product->inventory()->update(['quantity' => 0]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.'])
        ->assertSessionMissing('pending_cart_intent')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
});

test('a wishlist intent that expires during registration does not alter successful OTP sign in', function () {
    Mail::fake();
    $this->freezeTime();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $this->travel(14)->minutes();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'expired-wishlist@example.com',
        'password' => 'RegistrationPass!',
        'password_confirmation' => 'RegistrationPass!',
    ])->assertRedirectToRoute('verification.notice');
    $this->travel(1)->minutes();

    $this->post(route('verification.verify'), ['code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertInertiaFlashMissing('toast');

    $this->assertAuthenticated();
    $this->assertDatabaseCount('wishlist_items', 0);
    Mail::assertSentCount(1);
});
