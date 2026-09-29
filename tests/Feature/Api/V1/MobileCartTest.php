<?php

use App\Actions\Cart\BuildCartViewData;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;

test('mobile customers receive an empty cart without creating one', function () {
    $customer = User::factory()->customer()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/cart')
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [],
            'item_count' => 0,
            'total_quantity' => 0,
            'total' => '0.00',
            'conflict_count' => 0,
        ]]);

    $this->assertDatabaseCount('carts', 0);
});

test('mobile cart contains only owned items and matches web prices totals and conflicts', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $regular = Product::factory()->create(['price' => '10.15']);
    $discounted = Product::factory()->create(['price' => '5.00', 'discount_price' => '3.33']);
    Inventory::factory()->for($regular)->create(['quantity' => 10]);
    $stock = Inventory::factory()->for($discounted)->create(['quantity' => 10]);
    $service = app(CartService::class);
    $service->add($customer, $regular->id, 2);
    $service->add($customer, $discounted->id, 3);
    $service->add($otherCustomer, $regular->id, 5);
    $stock->update(['quantity' => 1]);

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/cart')
        ->assertOk()
        ->assertExactJson(['data' => app(BuildCartViewData::class)->execute($customer)])
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', '30.29')
        ->assertJsonPath('data.total_quantity', 5)
        ->assertJsonPath('data.conflict_count', 1)
        ->assertJsonPath('data.items.1.availability', [
            'status' => 'insufficient_stock', 'available_quantity' => 1,
        ]);
});

test('mobile additions accumulate quantity and ignore supplied ownership and price fields', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $otherCart = $otherCustomer->cart()->create();
    $product = Product::factory()->create(['price' => '10.15']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->postJson('/api/v1/cart/items', [
        'product_id' => $product->id, 'quantity' => 2,
        'user_id' => $otherCustomer->id, 'cart_id' => $otherCart->id, 'unit_price' => '0.01',
    ])->assertOk()->assertJsonPath('data.total', '20.30');

    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])
        ->assertOk()
        ->assertExactJson(['data' => app(BuildCartViewData::class)->execute($customer)])
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.total', '50.75');

    expect($otherCart->items()->count())->toBe(0)
        ->and($stock->refresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('cart_items', 1);
});

test('mobile quantity updates replace the quantity and can resolve reduced stock', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '10.15']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $item = app(CartService::class)->add($customer, $product->id, 5);
    $stock->update(['quantity' => 2]);

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 2])
        ->assertOk()
        ->assertExactJson(['data' => app(BuildCartViewData::class)->execute($customer)])
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.total', '20.30')
        ->assertJsonPath('data.conflict_count', 0);

    expect($item->refresh()->quantity)->toBe(2)
        ->and($stock->refresh()->quantity)->toBe(2);
});

test('mobile customers can remove unavailable items and receive the refreshed cart', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $item = app(CartService::class)->add($customer, $product->id, 2);
    $product->update(['is_active' => false]);

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->deleteJson('/api/v1/cart/items/'.$item->id)
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [], 'item_count' => 0, 'total_quantity' => 0,
            'total' => '0.00', 'conflict_count' => 0,
        ]]);

    $this->assertModelMissing($item);
    expect($stock->refresh()->quantity)->toBe(3);
});

test('every mobile cart route requires a customer bearer token', function (string $method, string $path, string $access) {
    $customer = User::factory()->customer()->create();

    if ($access === 'session') {
        $this->actingAs($customer);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    } elseif ($access === 'revoked') {
        $token = $customer->createToken('Phone');
        $token->accessToken->delete();
        $this->withToken($token->plainTextToken);
    } elseif ($access === 'expired') {
        $this->withToken($customer->createToken('Phone', ['*'], now()->subMinute())->plainTextToken);
    } elseif ($access === 'administrator') {
        $admin = User::factory()->administrator()->create();
        $this->withToken($admin->createToken('Phone')->plainTextToken);
    }

    $response = $this->{strtolower($method)}($path);

    if ($access === 'administrator') {
        $response->assertForbidden()->assertJsonStructure(['message']);
    } else {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    $this->assertDatabaseCount('carts', 0);
})->with([
    'retrieve' => ['GET', '/api/v1/cart'],
    'add' => ['POST', '/api/v1/cart/items'],
    'update' => ['PATCH', '/api/v1/cart/items/1'],
    'remove' => ['DELETE', '/api/v1/cart/items/1'],
])->with(['missing', 'invalid', 'revoked', 'expired', 'session', 'administrator']);

test('mobile customers cannot mutate foreign or missing cart items', function (string $method, bool $foreign) {
    $customer = User::factory()->customer()->create();
    $owner = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = app(CartService::class)->add($owner, $product->id, 2);
    $id = $foreign ? $item->id : $item->id + 100;

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->json($method, '/api/v1/cart/items/'.$id, ['quantity' => 1])
        ->assertNotFound()->assertJsonStructure(['message']);

    expect($item->refresh()->quantity)->toBe(2);
    $this->assertDatabaseCount('cart_items', 1);
})->with(['PATCH', 'DELETE'])->with([true, false]);

test('mobile cart mutations reject invalid quantities without changing items', function (string $method, mixed $quantity) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 10]);
    $item = app(CartService::class)->add($customer, $product->id, 2);
    $path = '/api/v1/cart/items'.($method === 'PATCH' ? '/'.$item->id : '');

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->{strtolower($method)}($path, ['product_id' => $product->id, 'quantity' => $quantity])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity')
        ->assertJsonStructure(['message', 'errors' => ['quantity']]);

    expect($item->refresh()->quantity)->toBe(2);
    $this->assertDatabaseCount('cart_items', 1);
})->with(['POST', 'PATCH'])->with([null, 0, -1, 1.5, 'not-a-number', 4294967296]);

test('mobile additions require an existing product', function (mixed $productId) {
    $customer = User::factory()->customer()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/cart/items', ['product_id' => $productId, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('product_id');

    $this->assertDatabaseCount('carts', 0);
})->with([null, 'invalid', 999]);

test('mobile stock conflicts use web validation messages and preserve the cart', function (string $method, string $state, string $field, string $message, ?int $available, string $status) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = app(CartService::class)->add($customer, $product->id, 2);

    match ($state) {
        'product' => $product->update(['is_active' => false]),
        'category' => $product->category->update(['is_active' => false]),
        'missing' => $stock->delete(),
        'empty' => $stock->update(['quantity' => 0]),
        'insufficient' => $stock->update(['quantity' => 1]),
    };
    $path = '/api/v1/cart/items'.($method === 'PATCH' ? '/'.$item->id : '');
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->json($method, $path, ['product_id' => $product->id, 'quantity' => 2])
        ->assertUnprocessable()
        ->assertExactJson(['message' => $message, 'errors' => [$field => [$message]]]);

    $this->get('/api/v1/cart')->assertOk()
        ->assertJsonPath('data.items.0.availability', ['status' => $status, 'available_quantity' => $available])
        ->assertJsonPath('data.conflict_count', 1);
    expect($item->refresh()->quantity)->toBe(2);
    $this->assertDatabaseCount('cart_items', 1);

    if ($state === 'missing') {
        $this->assertModelMissing($stock);
    } else {
        expect($stock->refresh()->quantity)->toBe($available);
    }
})->with(['POST', 'PATCH'])->with([
    'inactive product' => ['product', 'product_id', 'This product is no longer available.', 5, 'product_ineligible'],
    'inactive category' => ['category', 'product_id', 'This product is no longer available.', 5, 'product_ineligible'],
    'missing inventory' => ['missing', 'product_id', 'Stock information is unavailable for this product.', null, 'inventory_unavailable'],
    'out of stock' => ['empty', 'quantity', 'This product is out of stock.', 0, 'out_of_stock'],
    'insufficient stock' => ['insufficient', 'quantity', 'Only 1 item(s) are available.', 1, 'insufficient_stock'],
]);

test('mobile additions enforce cumulative stock and roll back failed first additions', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->assertDatabaseCount('carts', 0);

    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertOk();
    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
        ->assertUnprocessable()->assertJsonPath('errors.quantity', ['Only 3 item(s) are available.']);

    expect(CartItem::sole()->quantity)->toBe(2)
        ->and($stock->refresh()->quantity)->toBe(3);
});
