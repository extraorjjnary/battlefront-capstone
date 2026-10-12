<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('cart unit count uses one aggregate query and includes stale owned lines only', function () {
    $customer = User::factory()->customer()->create();
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->create(['quantity' => 3]);
    $staleItem = CartItem::factory()->for($cart)->create(['quantity' => 100]);
    $staleItem->product->update(['is_active' => false]);
    CartItem::factory()->create(['quantity' => 20]);
    DB::enableQueryLog();

    try {
        $quantity = app(CartService::class)->totalQuantity($customer);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($quantity)->toBe(103);
    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('sum(');
});

test('cart unit count refuses administrator callers without querying carts', function () {
    $administrator = User::factory()->administrator()->create();
    DB::enableQueryLog();

    try {
        expect(fn () => app(CartService::class)->totalQuantity($administrator))
            ->toThrow(AuthorizationException::class);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('customers with no cart or an empty persisted cart receive zero without creating items', function (bool $persisted) {
    $customer = User::factory()->customer()->create();
    if ($persisted) {
        Cart::factory()->for($customer)->create();
    }

    $this->actingAs($customer)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 0));

    $this->assertDatabaseCount('carts', $persisted ? 1 : 0);
    $this->assertDatabaseCount('cart_items', 0);
})->with(['no cart' => false, 'empty cart' => true]);

test('guest and administrator pages omit the cart count and do not query carts', function (string $account) {
    $product = Product::factory()->available()->create();
    CartItem::factory()->create(['quantity' => 7]);
    if ($account === 'administrator') {
        $this->actingAs(User::factory()->administrator()->create());
    }
    DB::enableQueryLog();

    try {
        $response = $this->get(route('products.show', $product));
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $response->assertInertia(fn (Assert $page) => $page->missing('cartQuantity'));
    expect(collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], '"carts"')
        || str_contains($query['query'], '"cart_items"')))->toBeEmpty();
})->with(['guest', 'administrator']);

test('cart count stays synchronized through mutation redirects and customer navigation', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $otherProduct = Product::factory()->available()->create();
    $this->actingAs($customer)->from(route('products.show', $product));

    $this->followingRedirects()->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 2));
    $this->followingRedirects()->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 3])
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 5));
    $this->followingRedirects()->post(route('cart.items.store'), ['product_id' => $otherProduct->id, 'quantity' => 1])
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 6));

    $item = $customer->cart->items()->where('product_id', $product->id)->sole();
    $this->followingRedirects()->patch(route('cart.items.update', $item), ['quantity' => 4])
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 5));
    $this->get(route('branches.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 5));
    $this->followingRedirects()->from(route('cart.index'))->delete(route('cart.items.destroy', $item))
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 1));
    $remaining = $customer->cart->items()->sole();
    $this->followingRedirects()->delete(route('cart.items.destroy', $remaining))
        ->assertInertia(fn (Assert $page) => $page->where('cartQuantity', 0));

    $this->assertDatabaseCount('cart_items', 0);
});

test('partial customer visits include fresh cart counts even when excluded', function (array $headers) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $item = app(CartService::class)->add($customer, $product->id, 2);
    $initial = $this->actingAs($customer)->get(route('products.show', $product));
    app(CartService::class)->updateQuantity($customer, $item->id, 5);

    $this->get(route('products.show', $product), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Show',
        ...$headers,
    ])->assertJsonPath('props.cartQuantity', 5);
})->with([
    'only product' => [['X-Inertia-Partial-Data' => 'product']],
    'except count' => [['X-Inertia-Partial-Except' => 'cartQuantity']],
]);

test('checkout redirects show the remaining cart quantity after partial or complete purchase', function (bool $purchaseAll) {
    $customer = User::factory()->customer()->create();
    $first = Product::factory()->available()->create();
    $second = Product::factory()->available()->create();
    $service = app(CartService::class);
    $firstItem = $service->add($customer, $first->id, 2);
    $secondItem = $service->add($customer, $second->id, 3);
    $ids = $purchaseAll ? [$firstItem->id, $secondItem->id] : [$firstItem->id];

    $this->actingAs($customer)->followingRedirects()->post(route('orders.store'), [
        'cart_item_ids' => $ids,
        'recipient_name' => 'Cart Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Show')
        ->where('cartQuantity', $purchaseAll ? 0 : 3));

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', $purchaseAll ? 2 : 1);
    $this->assertDatabaseCount('cart_items', $purchaseAll ? 0 : 1);
    expect(Order::sole()->items->sum('quantity'))->toBe($purchaseAll ? 5 : 2);
})->with(['partial purchase' => false, 'complete purchase' => true]);
