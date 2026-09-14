<?php

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Cart\ManageCart;
use App\Actions\Cart\ReviewCheckoutCart;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;

test('returns a current checkout summary for only the customer cart', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'name' => 'Battlefront Gaming GPU',
        'brand' => 'NVIDIA',
        'price' => '25000.00',
        'discount_price' => '24000.00',
    ]);
    $otherProduct = Product::factory()->create(['price' => '99999.00']);
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    Inventory::factory()->for($otherProduct)->create(['quantity' => 5]);
    $manageCart = new ManageCart;
    $item = $manageCart->add($customer, $product->id, 2);
    $manageCart->add($otherCustomer, $otherProduct->id, 1);

    $summary = (new ReviewCheckoutCart($manageCart))->execute($customer);

    expect($summary)->toBe([
        'items' => [[
            'id' => $item->id,
            'quantity' => 2,
            'product' => [
                'id' => $product->id,
                'name' => 'Battlefront Gaming GPU',
                'brand' => 'NVIDIA',
                'image_url' => $product->image_url,
            ],
            'unit_price' => '24000.00',
            'line_total' => '48000.00',
        ]],
        'item_count' => 1,
        'total_quantity' => 2,
        'total' => '48000.00',
    ]);
});

test('rejects an empty customer cart', function () {
    $customer = User::factory()->customer()->create();

    expect(fn () => (new ReviewCheckoutCart(new ManageCart))->execute($customer))
        ->toThrow(
            CheckoutUnavailableException::class,
            'Add at least one available product before checking out.',
        );
});

test('rejects cart items that exceed current stock', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $manageCart = new ManageCart;
    $manageCart->add($customer, $product->id, 3);
    $inventory->update(['quantity' => 2]);

    expect(fn () => (new ReviewCheckoutCart($manageCart))->execute($customer))
        ->toThrow(
            CheckoutUnavailableException::class,
            'Review unavailable products or quantities in your cart before checking out.',
        );
});
