<?php

use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Gate;

test('only customers are authorized to use cart operations', function () {
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();

    expect(Gate::forUser($customer)->allows('use-customer-cart'))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('use-customer-cart'))->toBeFalse();
});

test('guests are redirected to authentication for every cart mutation', function (string $method, string $routeName) {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $arguments = $routeName === 'cart.items.store' ? [] : [$item->id];
    $payload = $method === 'delete'
        ? []
        : ['product_id' => $item->product_id, 'quantity' => 1];

    $response = $this->{$method}(route($routeName, $arguments), $payload);

    $response->assertRedirectToRoute('login');
    expect($item->refresh()->quantity)->toBe(2)
        ->and(CartItem::query()->count())->toBe(1);
})->with([
    'add' => ['post', 'cart.items.store'],
    'update' => ['patch', 'cart.items.update'],
    'remove' => ['delete', 'cart.items.destroy'],
]);

test('administrators are forbidden from every customer cart mutation', function (string $method, string $routeName) {
    $administrator = User::factory()->administrator()->create();
    $item = CartItem::factory()->create(['quantity' => 2]);
    $arguments = $routeName === 'cart.items.store' ? [] : [$item->id];
    $payload = $method === 'delete'
        ? []
        : ['product_id' => $item->product_id, 'quantity' => 1];

    $this->actingAs($administrator)
        ->{$method}(route($routeName, $arguments), $payload)
        ->assertForbidden();

    expect($item->refresh()->quantity)->toBe(2)
        ->and(CartItem::query()->count())->toBe(1);
})->with([
    'add' => ['post', 'cart.items.store'],
    'update' => ['patch', 'cart.items.update'],
    'remove' => ['delete', 'cart.items.destroy'],
]);

test('customers can add update and remove an item through shared operations', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->post(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ])
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Product added to cart.');

    $item = $customer->refresh()->cart->items()->sole();

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->patch(route('cart.items.update', $item->id), ['quantity' => 4])
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Cart quantity updated.');

    expect($item->refresh()->quantity)->toBe(4);

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->delete(route('cart.items.destroy', $item->id))
        ->assertRedirect(route('products.index'))
        ->assertInertiaFlash('toast.message', 'Product removed from cart.');

    $this->assertModelMissing($item);
});

test('cart mutation requests enforce positive integer quantities', function (string $routeName, array $payload) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = (new CartService)->add($customer, $product->id, 2);
    $arguments = $routeName === 'cart.items.store' ? [] : [$item->id];

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->{$routeName === 'cart.items.store' ? 'post' : 'patch'}(
            route($routeName, $arguments),
            $payload,
        )
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors('quantity');

    expect($item->refresh()->quantity)->toBe(2);
})->with([
    'add zero' => ['cart.items.store', ['product_id' => 1, 'quantity' => 0]],
    'update zero' => ['cart.items.update', ['quantity' => 0]],
    'update decimal' => ['cart.items.update', ['quantity' => 1.5]],
]);

test('unavailable stock produces an explicit validation error without persistence', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->post(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => 3,
        ])
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([
            'quantity' => 'Only 2 item(s) are available.',
        ]);

    expect($customer->cart)->toBeNull();
});

test('stock changes are revalidated when a customer updates quantity', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = (new CartService)->add($customer, $product->id, 2);
    $inventory->update(['quantity' => 1]);

    $this->actingAs($customer)
        ->from(route('products.index'))
        ->patch(route('cart.items.update', $item->id), ['quantity' => 2])
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([
            'quantity' => 'Only 1 item(s) are available.',
        ]);

    expect($item->refresh()->quantity)->toBe(2);
});

test('customer routes conceal cart items owned by another customer', function (string $method, string $routeName) {
    $customer = User::factory()->customer()->create();
    $item = CartItem::factory()->create(['quantity' => 2]);
    $payload = $method === 'delete' ? [] : ['quantity' => 1];

    $this->actingAs($customer)
        ->{$method}(route($routeName, $item->id), $payload)
        ->assertNotFound();

    expect($item->refresh()->quantity)->toBe(2);
})->with([
    'update' => ['patch', 'cart.items.update'],
    'remove' => ['delete', 'cart.items.destroy'],
]);
