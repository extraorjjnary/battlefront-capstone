<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing inventory', function () {
    $this->get(route('administration.inventory.index'))
        ->assertRedirect(route('login'));
});

test('customers are forbidden from viewing inventory', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.inventory.index'))
        ->assertForbidden();
});

test('administrators can view initialized and uninitialized product inventory', function () {
    $administrator = User::factory()->administrator()->create();
    $initializedProduct = Product::factory()->create([
        'name' => 'AMD Ryzen 7 9700X',
        'brand' => 'AMD',
    ]);
    Inventory::factory()->for($initializedProduct)->create([
        'quantity' => 12,
        'reorder_level' => 4,
    ]);
    Product::factory()->inactive()->create([
        'name' => 'Legacy Graphics Card',
        'brand' => 'Legacy Brand',
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Inventory')
        ->where('filters.stock', 'all')
        ->where('low_stock_count', 0)
        ->has('products.data', 2)
        ->where('products.data.0.name', 'AMD Ryzen 7 9700X')
        ->where('products.data.0.is_low_stock', false)
        ->where('products.data.0.inventory.quantity', 12)
        ->where('products.data.0.inventory.reorder_level', 4)
        ->where('products.data.1.name', 'Legacy Graphics Card')
        ->where('products.data.1.is_active', false)
        ->where('products.data.1.is_low_stock', false)
        ->where('products.data.1.inventory', null));
});

test('administrators can filter products below their reorder level', function () {
    $administrator = User::factory()->administrator()->create();
    $lowStockProduct = Product::factory()->create(['name' => 'Low Stock Product']);
    $inactiveLowStockProduct = Product::factory()->inactive()->create([
        'name' => 'Inactive Low Stock Product',
    ]);
    $boundaryProduct = Product::factory()->create(['name' => 'Boundary Product']);
    $availableProduct = Product::factory()->create(['name' => 'Available Product']);
    Product::factory()->create(['name' => 'Uninitialized Product']);
    Inventory::factory()->for($lowStockProduct)->create([
        'quantity' => 4,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($inactiveLowStockProduct)->create([
        'quantity' => 0,
        'reorder_level' => 1,
    ]);
    Inventory::factory()->for($boundaryProduct)->create([
        'quantity' => 5,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($availableProduct)->create([
        'quantity' => 6,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => 'low']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Inventory')
        ->where('filters.stock', 'low')
        ->where('low_stock_count', 2)
        ->where('products.total', 2)
        ->where('products.data.0.name', 'Inactive Low Stock Product')
        ->where('products.data.0.is_active', false)
        ->where('products.data.0.is_low_stock', true)
        ->where('products.data.1.name', 'Low Stock Product')
        ->where('products.data.1.is_low_stock', true));
});

test('unknown stock filters show all products', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()->count(2)->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => 'unexpected']));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.stock', 'all')
        ->where('products.total', 2));
});

test('inventory products are paginated in stable name order', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()->count(26)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Product %02d', 26 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 25)
        ->where('products.total', 26)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.24.name', 'Product 25'));
});

test('low-stock pagination preserves the active filter', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()
        ->count(26)
        ->has(Inventory::factory()->state([
            'quantity' => 1,
            'reorder_level' => 2,
        ]))
        ->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => 'low']));

    $nextPageUrl = $response->inertiaProps('products.next_page_url');

    expect($nextPageUrl)->toContain('page=2')
        ->and($nextPageUrl)->toContain('stock=low');
});

test('guests cannot update inventory', function () {
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $this->patch(route('administration.inventory.update', $inventory), [
        'quantity' => 20,
        'reorder_level' => 6,
    ])->assertRedirect(route('login'));

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
});

test('customers cannot update inventory', function () {
    $customer = User::factory()->customer()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $this->actingAs($customer)
        ->patch(route('administration.inventory.update', $inventory), [
            'quantity' => 20,
            'reorder_level' => 6,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
});

test('administrators can update inventory stock values', function () {
    $administrator = User::factory()->administrator()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->patch(route('administration.inventory.update', $inventory), [
            'quantity' => 24,
            'reorder_level' => 8,
            'product_id' => Product::factory()->create()->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.inventory.index'));
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'product_id' => $inventory->product_id,
        'quantity' => 24,
        'reorder_level' => 8,
    ]);
});

test('invalid inventory values are rejected without changing stock', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->patch(route('administration.inventory.update', $inventory), $payload);

    $response
        ->assertRedirect(route('administration.inventory.index'))
        ->assertSessionHasErrors($errors);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
})->with([
    'required values' => [
        [],
        [
            'quantity' => 'Enter the current quantity.',
            'reorder_level' => 'Enter the reorder level.',
        ],
    ],
    'negative quantity' => [
        ['quantity' => -1, 'reorder_level' => 3],
        ['quantity' => 'Quantity must be zero or greater.'],
    ],
    'negative reorder level' => [
        ['quantity' => 10, 'reorder_level' => -1],
        ['reorder_level' => 'Reorder level must be zero or greater.'],
    ],
    'fractional values' => [
        ['quantity' => 1.5, 'reorder_level' => 2.5],
        [
            'quantity' => 'Quantity must be a whole number.',
            'reorder_level' => 'Reorder level must be a whole number.',
        ],
    ],
    'non-numeric values' => [
        ['quantity' => 'many', 'reorder_level' => 'few'],
        [
            'quantity' => 'Quantity must be a whole number.',
            'reorder_level' => 'Reorder level must be a whole number.',
        ],
    ],
    'values beyond storage range' => [
        ['quantity' => 4294967296, 'reorder_level' => 4294967296],
        [
            'quantity' => 'Quantity is too large.',
            'reorder_level' => 'Reorder level is too large.',
        ],
    ],
]);
