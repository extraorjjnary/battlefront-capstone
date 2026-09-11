<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can browse paginated customer eligible products in stable order', function () {
    $activeCategory = Category::factory()->create();
    $inactiveCategory = Category::factory()->inactive()->create();
    Product::factory()
        ->count(13)
        ->for($activeCategory)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Product %02d', 13 - $sequence->index),
        ])
        ->create();
    Product::factory()->for($activeCategory)->inactive()->create([
        'name' => 'Inactive product',
    ]);
    Product::factory()->for($inactiveCategory)->create([
        'name' => 'Hidden category product',
    ]);

    $response = $this->get(route('products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->has('products.data', 12)
        ->where('products.total', 13)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.11.name', 'Product 12'));

    expect(collect($response->inertiaProps('products.data'))->pluck('name'))
        ->not->toContain('Inactive product')
        ->not->toContain('Hidden category product');
});

test('the catalog returns an explicit empty result when no products are eligible', function () {
    Product::factory()->inactive()->create();

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->has('products.data', 0)
            ->where('products.total', 0));
});

test('product details use authoritative catalog relationships and stock data', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $performance = Tag::factory()->create(['name' => 'High Performance']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Atlas Graphics Card',
        'description' => 'A current product description from the catalog.',
        'brand' => 'Battlefront Demo',
        'price' => '39999.00',
        'discount_price' => '36999.00',
        'image_url' => 'https://example.test/products/atlas.png',
        'is_featured' => true,
    ]);
    $product->tags()->attach([$performance->id, $gaming->id]);
    Inventory::factory()->for($product)->create([
        'quantity' => 2,
        'reorder_level' => 3,
    ]);

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('product.id', $product->id)
            ->where('product.name', 'Atlas Graphics Card')
            ->where('product.description', 'A current product description from the catalog.')
            ->where('product.brand', 'Battlefront Demo')
            ->where('product.price', '39999.00')
            ->where('product.discount_price', '36999.00')
            ->where('product.image_url', 'https://example.test/products/atlas.png')
            ->where('product.is_featured', true)
            ->where('product.category.name', 'Graphics Cards')
            ->where('product.tags.0.name', 'Gaming')
            ->where('product.tags.1.name', 'High Performance')
            ->where('product.inventory.quantity', 2)
            ->where('product.inventory.status', 'low_stock'));
});

test('product details distinguish out of stock and unavailable inventory', function (bool $hasInventory, string $expectedStatus) {
    $product = Product::factory()->create();

    if ($hasInventory) {
        Inventory::factory()->for($product)->create([
            'quantity' => 0,
            'reorder_level' => 3,
        ]);
    }

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('product.inventory.quantity', $hasInventory ? 0 : null)
            ->where('product.inventory.status', $expectedStatus));
})->with([
    'out of stock' => [true, 'out_of_stock'],
    'inventory unavailable' => [false, 'unavailable'],
]);

test('customer ineligible products are not exposed', function (Product $product) {
    $this->get(route('products.show', $product))->assertNotFound();
})->with([
    'inactive product' => fn (): Product => Product::factory()->inactive()->create(),
    'product in inactive category' => fn (): Product => Product::factory()
        ->for(Category::factory()->inactive())
        ->create(),
]);

test('unknown product identifiers return a not found response', function (string $identifier) {
    $this->get(route('products.show', $identifier))->assertNotFound();
})->with([
    'missing numeric identifier' => '999999',
    'non-numeric identifier' => 'not-a-product',
]);
