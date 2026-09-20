<?php

use App\Actions\Cart\CartOperationException;
use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('adding a product creates the customer cart and item', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);

    $item = (new CartService)->add($customer, $product->id, 2);

    $this->assertModelExists($item);
    expect($customer->refresh()->cart->is($item->cart))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($item->quantity)->toBe(2);
});

test('adding the same product accumulates one cart item quantity', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    $cartService = new CartService;

    $firstItem = $cartService->add($customer, $product->id, 2);
    $secondItem = $cartService->add($customer, $product->id, 3);

    expect($secondItem->is($firstItem))->toBeTrue()
        ->and($secondItem->quantity)->toBe(5)
        ->and($customer->cart->items()->count())->toBe(1);
});

test('direct callers cannot add non-positive quantities', function (int $quantity) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);

    expect(fn () => (new CartService)->add($customer, $product->id, $quantity))
        ->toThrow(InvalidArgumentException::class, 'Cart item quantity must be greater than zero.');
    expect($customer->cart)->toBeNull();
})->with([
    'zero' => 0,
    'negative' => -1,
]);

test('administrators cannot call customer cart operations directly', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);

    expect(fn () => (new CartService)->add($administrator, $product->id, 1))
        ->toThrow(AuthorizationException::class);
});

test('adding beyond stock is rejected without creating cart data', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);

    expect(fn () => (new CartService)->add($customer, $product->id, 3))
        ->toThrow(CartOperationException::class, 'Only 2 item(s) are available.');
    expect($customer->cart)->toBeNull()
        ->and(CartItem::query()->count())->toBe(0);
});

test('adding an ineligible product returns an explicit availability outcome', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->inactive()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);

    try {
        (new CartService)->add($customer, $product->id, 1);
        $this->fail('The ineligible product was added to the cart.');
    } catch (CartOperationException $exception) {
        expect($exception->outcome)->toBe(CartAvailability::ProductIneligible)
            ->and($exception->field())->toBe('product_id');
    }

    expect($customer->cart)->toBeNull();
});

test('adding to an existing item validates the combined quantity', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 4]);
    $cartService = new CartService;
    $item = $cartService->add($customer, $product->id, 3);

    expect(fn () => $cartService->add($customer, $product->id, 2))
        ->toThrow(CartOperationException::class, 'Only 4 item(s) are available.');
    expect($item->refresh()->quantity)->toBe(3);
});

test('updating replaces the quantity and permits lowering it to current stock', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $cartService = new CartService;
    $item = $cartService->add($customer, $product->id, 5);
    $inventory->update(['quantity' => 3]);

    $updatedItem = $cartService->updateQuantity($customer, $item->id, 3);

    expect($updatedItem->quantity)->toBe(3);
});

test('updating beyond current stock is rejected without changing the item', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    $cartService = new CartService;
    $item = $cartService->add($customer, $product->id, 2);

    expect(fn () => $cartService->updateQuantity($customer, $item->id, 6))
        ->toThrow(CartOperationException::class, 'Only 5 item(s) are available.');
    expect($item->refresh()->quantity)->toBe(2);
});

test('customers cannot update or remove another customers items', function (string $operation) {
    $owner = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $item = CartItem::factory()->for($owner->cart()->create())->create();
    $cartService = new CartService;

    $callback = $operation === 'update'
        ? fn () => $cartService->updateQuantity($otherCustomer, $item->id, 2)
        : fn () => $cartService->remove($otherCustomer, $item->id);

    expect($callback)->toThrow(ModelNotFoundException::class);
    $this->assertModelExists($item);
})->with(['update', 'remove']);

test('stale items can still be removed after stock becomes unavailable', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 2]);
    $cartService = new CartService;
    $item = $cartService->add($customer, $product->id, 2);
    $inventory->update(['quantity' => 0]);

    $cartService->remove($customer, $item->id);

    $this->assertModelMissing($item);
});

test('totals use current prices and exact decimal arithmetic', function () {
    $customer = User::factory()->customer()->create();
    $regularProduct = Product::factory()->create(['price' => '10.15']);
    $discountedProduct = Product::factory()->create([
        'price' => '9.99',
        'discount_price' => '3.33',
    ]);
    Inventory::factory()->for($regularProduct)->create(['quantity' => 10]);
    Inventory::factory()->for($discountedProduct)->create(['quantity' => 10]);
    $cartService = new CartService;
    $regularItem = $cartService->add($customer, $regularProduct->id, 2);
    $discountedItem = $cartService->add($customer, $discountedProduct->id, 3);

    $totals = $cartService->totals($customer);

    expect($totals)->toBe([
        'lines' => [
            [
                'cart_item_id' => $regularItem->id,
                'quantity' => 2,
                'unit_price' => '10.15',
                'line_total' => '20.30',
            ],
            [
                'cart_item_id' => $discountedItem->id,
                'quantity' => 3,
                'unit_price' => '3.33',
                'line_total' => '9.99',
            ],
        ],
        'item_count' => 2,
        'total_quantity' => 5,
        'total' => '30.29',
    ]);
});

test('empty carts have zero totals without creating persistence', function () {
    $customer = User::factory()->customer()->create();

    expect((new CartService)->totals($customer))->toBe([
        'lines' => [],
        'item_count' => 0,
        'total_quantity' => 0,
        'total' => '0.00',
    ])->and($customer->cart)->toBeNull();
});

test('availability reports explicit outcomes for current catalog and stock changes', function () {
    $customer = User::factory()->customer()->create();
    $cartService = new CartService;

    $availableProduct = Product::factory()->create();
    Inventory::factory()->for($availableProduct)->create(['quantity' => 3]);
    $availableItem = $cartService->add($customer, $availableProduct->id, 2);

    $inactiveProduct = Product::factory()->create();
    Inventory::factory()->for($inactiveProduct)->create(['quantity' => 3]);
    $inactiveItem = $cartService->add($customer, $inactiveProduct->id, 1);
    $inactiveProduct->update(['is_active' => false]);

    $missingInventoryProduct = Product::factory()->create();
    $missingInventory = Inventory::factory()->for($missingInventoryProduct)->create(['quantity' => 3]);
    $missingInventoryItem = $cartService->add($customer, $missingInventoryProduct->id, 1);
    $missingInventory->delete();

    $outOfStockProduct = Product::factory()->create();
    $outOfStockInventory = Inventory::factory()->for($outOfStockProduct)->create(['quantity' => 3]);
    $outOfStockItem = $cartService->add($customer, $outOfStockProduct->id, 1);
    $outOfStockInventory->update(['quantity' => 0]);

    $insufficientProduct = Product::factory()->create();
    $insufficientInventory = Inventory::factory()->for($insufficientProduct)->create(['quantity' => 3]);
    $insufficientItem = $cartService->add($customer, $insufficientProduct->id, 3);
    $insufficientInventory->update(['quantity' => 2]);

    expect($cartService->availability($customer))->toBe([
        [
            'cart_item_id' => $availableItem->id,
            'requested_quantity' => 2,
            'available_quantity' => 3,
            'status' => CartAvailability::Available->value,
        ],
        [
            'cart_item_id' => $inactiveItem->id,
            'requested_quantity' => 1,
            'available_quantity' => 3,
            'status' => CartAvailability::ProductIneligible->value,
        ],
        [
            'cart_item_id' => $missingInventoryItem->id,
            'requested_quantity' => 1,
            'available_quantity' => null,
            'status' => CartAvailability::InventoryUnavailable->value,
        ],
        [
            'cart_item_id' => $outOfStockItem->id,
            'requested_quantity' => 1,
            'available_quantity' => 0,
            'status' => CartAvailability::OutOfStock->value,
        ],
        [
            'cart_item_id' => $insufficientItem->id,
            'requested_quantity' => 3,
            'available_quantity' => 2,
            'status' => CartAvailability::InsufficientStock->value,
        ],
    ]);
});
