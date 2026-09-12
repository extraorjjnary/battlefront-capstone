<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the cart item schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('cart_items'))->toEqualCanonicalizing([
        'id',
        'cart_id',
        'product_id',
        'quantity',
    ]);
});

test('a cart item persists through its reciprocal relationships', function () {
    $item = CartItem::factory()->create(['quantity' => 3]);

    $this->assertModelExists($item);
    expect($item->quantity)->toBe(3)
        ->and($item->quantity)->toBeInt()
        ->and($item->cart->items->sole()->is($item))->toBeTrue()
        ->and($item->product->cartItems->sole()->is($item))->toBeTrue();
});

test('a product appears only once in a cart', function () {
    $item = CartItem::factory()->create();

    expect(fn () => CartItem::factory()
        ->for($item->cart)
        ->for($item->product)
        ->create())
        ->toThrow(QueryException::class);
});

test('non-positive cart quantities are rejected when inserted', function (int $quantity) {
    expect(fn () => CartItem::factory()->create(['quantity' => $quantity]))
        ->toThrow(QueryException::class);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

test('non-positive cart quantities are rejected when updated', function (int $quantity) {
    $item = CartItem::factory()->create(['quantity' => 3]);

    expect(fn () => $item->update(['quantity' => $quantity]))
        ->toThrow(QueryException::class);
    expect($item->refresh()->quantity)->toBe(3);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

test('unavailable or customer-ineligible products are rejected', function (Product $product) {
    $cart = Cart::factory()->create();

    expect(fn () => CartItem::factory()->for($cart)->for($product)->create())
        ->toThrow(DomainException::class, 'Cart items require an available customer-eligible product.');
    expect(CartItem::query()->count())->toBe(0);
})->with([
    'inactive product' => function (): Product {
        $product = Product::factory()->inactive()->create();
        Inventory::factory()->for($product)->create(['quantity' => 10]);

        return $product;
    },
    'inactive category' => function (): Product {
        $product = Product::factory()
            ->for(Category::factory()->inactive())
            ->create();
        Inventory::factory()->for($product)->create(['quantity' => 10]);

        return $product;
    },
    'missing inventory' => fn (): Product => Product::factory()->create(),
    'zero stock' => function (): Product {
        $product = Product::factory()->create();
        Inventory::factory()->for($product)->create(['quantity' => 0]);

        return $product;
    },
]);

test('an active low-stock product remains eligible for a cart', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create([
        'quantity' => 1,
        'reorder_level' => 5,
    ]);

    $item = CartItem::factory()->for($product)->create(['quantity' => 1]);

    $this->assertModelExists($item);
});

test('an existing cart item cannot be changed to an ineligible product', function () {
    $item = CartItem::factory()->create();
    $originalProduct = $item->product;
    $inactiveProduct = Product::factory()->inactive()->create();
    Inventory::factory()->for($inactiveProduct)->create(['quantity' => 10]);

    expect(fn () => $item->update(['product_id' => $inactiveProduct->id]))
        ->toThrow(DomainException::class, 'Cart items require an available customer-eligible product.');
    expect($item->refresh()->product->is($originalProduct))->toBeTrue();
});

test('a product referenced by a cart item cannot be deleted', function () {
    $item = CartItem::factory()->create();

    expect(fn () => $item->product->delete())
        ->toThrow(QueryException::class);
});
