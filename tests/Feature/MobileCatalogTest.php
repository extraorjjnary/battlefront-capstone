<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;

test('guests receive twelve eligible products per page with stable ordering and filter links', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->count(13)->for($category)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Atlas %02d', $sequence->index + 1),
            'brand' => 'Atlas Hardware',
        ])->create();
    $products->each(fn (Product $product) => $product->tags()->attach($tag));
    $products->last()->update(['is_featured' => true]);
    Product::factory()->for($category)->inactive()->create(['name' => 'Atlas Hidden']);
    Product::factory()->for(Category::factory()->inactive())->create(['name' => 'Atlas Hidden Category']);
    $filters = ['q' => 'Atlas', 'category_id' => $category->id, 'brand' => 'Atlas Hardware', 'tag_id' => $tag->id];

    $firstPage = $this->get(route('api.v1.products.index', $filters));
    $secondPage = $this->get(route('api.v1.products.index', [...$filters, 'page' => 2]));

    $firstPage->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('data.0.id', $products->last()->id)
        ->assertJsonPath('data.1.name', 'Atlas 01')
        ->assertJsonPath('meta.per_page', 12)
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    $secondPage->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Atlas 12')
        ->assertJsonPath('meta.current_page', 2);
    expect($firstPage->json('links.next'))->toContain('q=Atlas')
        ->toContain('category_id='.$category->id)
        ->toContain('brand=Atlas%20Hardware')
        ->toContain('tag_id='.$tag->id);
});

test('catalog search matches names brands and descriptions with the same web results', function () {
    Product::factory()->create(['name' => 'Atlas Graphics Card']);
    Product::factory()->create(['name' => 'Keyboard', 'brand' => 'Atlas Hardware']);
    Product::factory()->create(['name' => 'Processor', 'description' => 'Built for Atlas workstations.']);
    Product::factory()->create(['name' => 'Unrelated Monitor']);
    Product::factory()->inactive()->create(['name' => 'Inactive Atlas Product']);

    $response = $this->get(route('api.v1.products.index', ['q' => 'Atlas']));
    $webResponse = $this->get(route('products.index', ['q' => 'Atlas']));

    $response->assertOk()->assertJsonCount(3, 'data');
    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Atlas Graphics Card', 'Keyboard', 'Processor']);
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe(collect($webResponse->inertiaProps('products.data'))->pluck('id')->all());
});

test('catalog category brand and tag filters combine', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $matching = Product::factory()->for($category)->create(['brand' => 'AMD']);
    $matching->tags()->attach($tag);
    $wrongCategory = Product::factory()->create(['brand' => 'AMD']);
    $wrongCategory->tags()->attach($tag);
    $wrongBrand = Product::factory()->for($category)->create(['brand' => 'Intel']);
    $wrongBrand->tags()->attach($tag);
    Product::factory()->for($category)->create(['brand' => 'AMD']);

    $this->get(route('api.v1.products.index', [
        'category_id' => $category->id, 'brand' => 'AMD', 'tag_id' => $tag->id,
    ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
});

test('filter options include only eligible categories brands and tags', function () {
    $category = Category::factory()->create(['name' => 'Processors']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($category)->create(['brand' => 'AMD']);
    $product->tags()->attach($tag);
    Product::factory()->for($category)->create(['brand' => null]);
    $hidden = Product::factory()->for(Category::factory()->inactive())->create(['brand' => 'Hidden Brand']);
    $hidden->tags()->attach(Tag::factory()->create());
    Product::factory()->inactive()->create(['brand' => 'Inactive Brand']);
    Category::factory()->create();
    Tag::factory()->create();

    $this->get(route('api.v1.products.filters'))->assertOk()->assertExactJson(['data' => [
        'categories' => [['id' => $category->id, 'name' => 'Processors']],
        'brands' => ['AMD'],
        'tags' => [['id' => $tag->id, 'name' => 'Gaming']],
    ]]);
});

test('product detail returns only the mobile catalog fields', function () {
    config(['filesystems.disks.public.url' => 'https://assets.example.test/storage']);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $performance = Tag::factory()->create(['name' => 'Performance']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Atlas Graphics Card', 'description' => 'Current catalog description.',
        'brand' => 'Atlas', 'price' => '39999.00', 'discount_price' => '36999.00',
        'image_path' => 'products/atlas.webp', 'is_featured' => true,
    ]);
    $product->tags()->attach([$performance->id, $gaming->id]);
    Inventory::factory()->for($product)->create(['quantity' => 2, 'reorder_level' => 3]);

    $this->get(route('api.v1.products.show', $product))->assertOk()->assertExactJson(['data' => [
        'id' => $product->id, 'name' => 'Atlas Graphics Card',
        'description' => 'Current catalog description.', 'brand' => 'Atlas',
        'price' => '39999.00', 'discount_price' => '36999.00',
        'image_url' => 'https://assets.example.test/storage/products/atlas.webp',
        'is_featured' => true, 'category' => ['id' => $category->id, 'name' => 'Graphics Cards'],
        'tags' => [['id' => $gaming->id, 'name' => 'Gaming'], ['id' => $performance->id, 'name' => 'Performance']],
        'inventory' => ['status' => 'low_stock'],
    ]]);
});

test('list and detail expose stock status without exact quantities', function (?int $quantity, int $reorderLevel, string $status) {
    $product = Product::factory()->create(['image_path' => null, 'brand' => null]);
    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
    }

    $this->get(route('api.v1.products.index'))->assertOk()
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.inventory', ['status' => $status])
        ->assertJsonMissingPath('data.0.inventory.quantity')
        ->assertJsonMissingPath('data.0.inventory.reorder_level');
    $this->get(route('api.v1.products.show', $product))->assertOk()
        ->assertJsonPath('data.inventory', ['status' => $status])
        ->assertJsonPath('data.image_url', null)
        ->assertJsonPath('data.brand', null);
})->with([
    'missing inventory' => [null, 3, 'unavailable'],
    'zero stock' => [0, 3, 'out_of_stock'],
    'below threshold' => [2, 3, 'low_stock'],
    'at threshold' => [3, 3, 'in_stock'],
    'above threshold' => [4, 3, 'in_stock'],
    'zero threshold' => [1, 0, 'in_stock'],
]);

test('subsequent product requests reflect current Sagay inventory', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5, 'reorder_level' => 3]);

    $this->get(route('api.v1.products.show', $product))->assertJsonPath('data.inventory.status', 'in_stock');
    $inventory->update(['quantity' => 0]);

    $this->get(route('api.v1.products.show', $product))->assertJsonPath('data.inventory.status', 'out_of_stock');
});

test('inactive products and categories are excluded from lists and return 404 on detail', function (Product $product) {
    $this->get(route('api.v1.products.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->get(route('api.v1.products.show', $product))->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
})->with([
    'inactive product' => fn (): Product => Product::factory()->inactive()->create(),
    'inactive category' => fn (): Product => Product::factory()->for(Category::factory()->inactive())->create(),
]);

test('unknown product identifiers return JSON 404', function (string $identifier) {
    $this->get('/api/v1/products/'.$identifier)->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
})->with(['999999', 'not-a-product']);

test('invalid catalog input returns JSON 422 without an Accept header', function (string $field, mixed $value, string $message) {
    $this->get(route('api.v1.products.index', [$field => $value]))
        ->assertUnprocessable()->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors'])->assertJsonPath('errors.'.$field.'.0', $message);
})->with([
    'search type' => ['q', ['invalid'], 'Search must be text.'],
    'search length' => ['q', str_repeat('a', 256), 'Search may not be longer than 255 characters.'],
    'category type' => ['category_id', 'invalid', 'Select a valid category.'],
    'missing category' => ['category_id', 999999, 'Select an available category.'],
    'brand type' => ['brand', ['invalid'], 'Select a valid brand.'],
    'missing tag' => ['tag_id', 999999, 'Select a valid tag.'],
    'page value' => ['page', 0, 'The catalog page must be at least 1.'],
]);

test('an inactive category filter returns JSON 422', function () {
    $category = Category::factory()->inactive()->create();

    $this->get(route('api.v1.products.index', ['category_id' => $category->id]))
        ->assertUnprocessable()->assertJsonPath('errors.category_id.0', 'Select an available category.');
});

test('unmatched filters return an empty paginated collection', function () {
    Product::factory()->create(['brand' => 'AMD']);

    $this->get(route('api.v1.products.index', ['brand' => 'Intel']))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('unsupported stock filtering does not hide eligible products', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5, 'reorder_level' => 2]);
    Product::factory()->create();

    $this->get(route('api.v1.products.index', ['stock' => 'out_of_stock']))
        ->assertOk()->assertJsonCount(2, 'data');
});

test('mobile categories combine multiple categories with effective price and search filters', function () {
    $categories = Category::factory()->count(2)->create();
    foreach ($categories as $category) {
        Product::factory()->for($category)->create(['name' => 'Selected GPU', 'brand' => 'Atlas', 'price' => '9000.00', 'discount_price' => '4999.99']);
        Product::factory()->for($category)->create(['name' => 'Selected GPU', 'brand' => 'Atlas', 'price' => '5000.00', 'discount_price' => null]);
        Product::factory()->for($category)->create(['name' => 'Selected GPU', 'brand' => 'Other', 'price' => '100.00']);
    }
    Product::factory()->create(['name' => 'Selected GPU', 'brand' => 'Atlas', 'price' => '100.00']);

    $this->getJson(route('api.v1.products.index', ['category_ids' => $categories->modelKeys(), 'brand' => 'Atlas', 'q' => 'GPU', 'max_price' => '4999.99']))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson(route('api.v1.products.index', ['category_ids' => $categories->modelKeys(), 'brand' => 'Atlas', 'min_price' => '5000']))
        ->assertOk()->assertJsonCount(2, 'data');
});

test('price ordering uses discounts and stays stable across pages', function () {
    $products = Product::factory()->count(13)->sequence(fn ($sequence): array => [
        'name' => sprintf('Part %02d', $sequence->index), 'price' => '20000.00', 'discount_price' => '15000.00',
    ])->create();
    $cheapest = Product::factory()->create(['name' => 'Discounted', 'price' => '30000.00', 'discount_price' => '14999.99']);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_asc']))
        ->assertOk()->assertJsonPath('data.0.id', $cheapest->id)->assertJsonPath('meta.total', 14);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_asc', 'page' => 2]))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $products[11]->id);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_desc', 'min_price' => '15000']))
        ->assertOk()->assertJsonPath('data.0.id', $products[0]->id)->assertJsonPath('meta.total', 13);
    $this->getJson(route('api.v1.products.index', ['max_price' => '14999.99']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheapest->id);
});

test('invalid category price and sort inputs are rejected', function (array $filters, string $field) {
    $this->getJson(route('api.v1.products.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['category_ids' => 'one'], 'category_ids'],
    [['category_ids' => [999999]], 'category_ids.0'],
    [['min_price' => '-1'], 'min_price'],
    [['min_price' => '20', 'max_price' => '10'], 'max_price'],
    [['max_price' => '1.001'], 'max_price'],
    [['sort' => 'price desc; DROP TABLE products'], 'sort'],
]);
