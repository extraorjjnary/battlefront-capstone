<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
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

test('customers can search eligible product names brands and descriptions', function () {
    Product::factory()->create(['name' => 'Atlas Graphics Card']);
    Product::factory()->create([
        'name' => 'Keyboard',
        'brand' => 'Atlas Hardware',
    ]);
    Product::factory()->create([
        'name' => 'Processor',
        'description' => 'Built for Atlas workstations.',
    ]);
    Product::factory()->create(['name' => 'Unrelated Monitor']);
    Product::factory()->inactive()->create(['name' => 'Inactive Atlas Product']);

    $response = $this->get(route('products.index', ['q' => 'Atlas']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->where('filters.q', 'Atlas')
        ->has('products.data', 3));

    expect(collect($response->inertiaProps('products.data'))->pluck('name')->all())
        ->toBe(['Atlas Graphics Card', 'Keyboard', 'Processor']);
});

test('customers can combine category brand and tag filters', function () {
    $processors = Category::factory()->create(['name' => 'Processors']);
    $storage = Category::factory()->create(['name' => 'Storage']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $office = Tag::factory()->create(['name' => 'Office']);

    $matchingProduct = Product::factory()->for($processors)->create([
        'name' => 'Matching Processor',
        'brand' => 'AMD',
    ]);
    $matchingProduct->tags()->attach($gaming);

    $wrongCategory = Product::factory()->for($storage)->create([
        'name' => 'Wrong Category',
        'brand' => 'AMD',
    ]);
    $wrongCategory->tags()->attach($gaming);

    $wrongBrand = Product::factory()->for($processors)->create([
        'name' => 'Wrong Brand',
        'brand' => 'Intel',
    ]);
    $wrongBrand->tags()->attach($gaming);

    $wrongTag = Product::factory()->for($processors)->create([
        'name' => 'Wrong Tag',
        'brand' => 'AMD',
    ]);
    $wrongTag->tags()->attach($office);

    $response = $this->get(route('products.index', [
        'category_id' => $processors->id,
        'brand' => 'AMD',
        'tag_id' => $gaming->id,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.category_id', $processors->id)
        ->where('filters.brand', 'AMD')
        ->where('filters.tag_id', $gaming->id)
        ->has('products.data', 1)
        ->where('products.data.0.id', $matchingProduct->id));
});

test('stock availability is not accepted as a customer catalog filter', function () {
    $inStock = Product::factory()->create(['name' => 'In Stock Product']);
    Inventory::factory()->for($inStock)->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $outOfStock = Product::factory()->create(['name' => 'Out Of Stock Product']);
    Inventory::factory()->for($outOfStock)->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $this->get(route('products.index', ['stock' => 'out_of_stock']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('filters.stock')
            ->missing('filter_options.stock')
            ->has('products.data', 2));
});

test('catalog pagination preserves active filters and deterministic ordering', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()
        ->count(13)
        ->for($category)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Gaming Product %02d', $sequence->index + 1),
            'brand' => 'Battlefront Demo',
        ])
        ->create();
    $products->each(fn (Product $product) => $product->tags()->attach($tag));

    $response = $this->get(route('products.index', [
        'q' => 'Gaming',
        'category_id' => $category->id,
        'brand' => 'Battlefront Demo',
        'tag_id' => $tag->id,
        'page' => 2,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Gaming Product 13'));

    expect($response->inertiaProps('products.prev_page_url'))
        ->toContain('q=Gaming')
        ->toContain('category_id='.$category->id)
        ->toContain('brand=Battlefront%20Demo')
        ->toContain('tag_id='.$tag->id);
});

test('catalog query parameters are validated', function (string $field, mixed $value, string $message) {
    $this->from(route('products.index'))
        ->get(route('products.index', [$field => $value]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'search type' => ['q', ['invalid'], 'Search must be text.'],
    'search length' => ['q', str_repeat('a', 256), 'Search may not be longer than 255 characters.'],
    'category identifier' => ['category_id', 'invalid', 'Select a valid category.'],
    'brand type' => ['brand', ['invalid'], 'Select a valid brand.'],
    'tag identifier' => ['tag_id', 999999, 'Select a valid tag.'],
    'page value' => ['page', 0, 'The catalog page must be at least 1.'],
]);

test('inactive categories are rejected as catalog filters', function () {
    $inactiveCategory = Category::factory()->inactive()->create();

    $this->from(route('products.index'))
        ->get(route('products.index', ['category_id' => $inactiveCategory->id]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([
            'category_id' => 'Select an available category.',
        ]);
});

test('filter options do not expose values from customer ineligible products', function () {
    $visibleCategory = Category::factory()->create(['name' => 'Visible Category']);
    $visibleTag = Tag::factory()->create(['name' => 'Visible Tag']);
    $visibleProduct = Product::factory()->for($visibleCategory)->create([
        'brand' => 'Visible Brand',
    ]);
    $visibleProduct->tags()->attach($visibleTag);

    $hiddenCategory = Category::factory()->inactive()->create([
        'name' => 'Hidden Category',
    ]);
    $hiddenTag = Tag::factory()->create(['name' => 'Hidden Tag']);
    $hiddenProduct = Product::factory()->for($hiddenCategory)->create([
        'brand' => 'Hidden Brand',
    ]);
    $hiddenProduct->tags()->attach($hiddenTag);

    $response = $this->get(route('products.index'));

    expect($response->inertiaProps('filter_options.categories'))->toHaveCount(1)
        ->and($response->inertiaProps('filter_options.categories.0.name'))->toBe('Visible Category')
        ->and($response->inertiaProps('filter_options.brands'))->toBe(['Visible Brand'])
        ->and($response->inertiaProps('filter_options.tags'))->toHaveCount(1)
        ->and($response->inertiaProps('filter_options.tags.0.name'))->toBe('Visible Tag');
});

test('filtered catalog results are consistent for guests and authenticated customers', function () {
    Product::factory()->create([
        'name' => 'Customer Product',
        'brand' => 'Battlefront Demo',
    ]);
    $customer = User::factory()->create();
    $url = route('products.index', ['brand' => 'Battlefront Demo']);

    $guestResponse = $this->get($url);
    $customerResponse = $this->actingAs($customer)->get($url);

    expect($customerResponse->inertiaProps('products'))->toBe($guestResponse->inertiaProps('products'))
        ->and($customerResponse->inertiaProps('filters'))->toBe($guestResponse->inertiaProps('filters'))
        ->and($customerResponse->inertiaProps('filter_options'))->toBe($guestResponse->inertiaProps('filter_options'));
});

test('the catalog returns an explicit empty result when filters have no matches', function () {
    Product::factory()->create(['brand' => 'AMD']);

    $this->get(route('products.index', ['brand' => 'Intel']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.brand', 'Intel')
            ->has('products.data', 0)
            ->where('products.total', 0));
});
