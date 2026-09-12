<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the cart schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('carts'))->toEqualCanonicalizing([
        'id',
        'user_id',
        'created_at',
        'updated_at',
    ]);
});

test('a customer owns one cart through reciprocal relationships', function () {
    $customer = User::factory()->customer()->create();
    $cart = Cart::factory()->for($customer)->create();

    $this->assertModelExists($cart);
    expect($cart->user->is($customer))->toBeTrue()
        ->and($customer->cart->is($cart))->toBeTrue();
});

test('a customer cannot own more than one cart', function () {
    $customer = User::factory()->customer()->create();
    Cart::factory()->for($customer)->create();

    expect(fn () => Cart::factory()->for($customer)->create())
        ->toThrow(QueryException::class);
});

test('an administrator cannot own a cart', function () {
    $administrator = User::factory()->administrator()->create();

    expect(fn () => Cart::factory()->for($administrator)->create())
        ->toThrow(DomainException::class, 'Carts may only belong to customers.');
    expect(Cart::query()->count())->toBe(0);
});

test('cart items remain isolated to their owning customers', function () {
    $firstCart = Cart::factory()->create();
    $secondCart = Cart::factory()->create();
    $firstItem = CartItem::factory()->for($firstCart)->create();
    $secondItem = CartItem::factory()->for($secondCart)->create();

    expect($firstCart->items->modelKeys())->toBe([$firstItem->id])
        ->and($secondCart->items->modelKeys())->toBe([$secondItem->id])
        ->and($firstCart->user->cart->is($firstCart))->toBeTrue()
        ->and($secondCart->user->cart->is($secondCart))->toBeTrue();
});

test('deleting a customer removes its transient cart data', function () {
    $cart = Cart::factory()->create();
    $item = CartItem::factory()->for($cart)->create();

    $cart->user->delete();

    $this->assertModelMissing($cart);
    $this->assertModelMissing($item);
});
