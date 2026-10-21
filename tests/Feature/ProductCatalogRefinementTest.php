<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the main catalog prioritizes only available featured products without a showcase prop', function () {
    $category = Category::factory()->create();
    $featured = Product::factory()->for($category)->create([
        'name' => 'Featured hardware', 'is_featured' => true,
        'price' => '500.00', 'discount_price' => '400.00',
    ]);
    $inventory = Inventory::factory()->for($featured)->create(['quantity' => 2, 'reorder_level' => 3]);
    $ordinary = Product::factory()->available()->create(['name' => 'Alpha ordinary hardware']);
    Product::factory()->available()->inactive()->create(['is_featured' => true]);
    Product::factory()->available()->for(Category::factory()->inactive())->create(['is_featured' => true]);
    Product::factory()->create(['is_featured' => true]);
    Product::factory()->has(Inventory::factory()->state(['quantity' => 0]))->create(['is_featured' => true]);

    $response = $this->get(route('products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('featured_products')
        ->has('products.data', 2)
        ->where('products.data.0.id', $featured->id)
        ->where('products.data.0.is_featured', true)
        ->where('products.data.0.inventory.status', 'low_stock')
        ->where('products.data.1.id', $ordinary->id));

    $inventory->update(['quantity' => 0]);
    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $ordinary->id));
    $inventory->update(['quantity' => 5]);
    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.id', $featured->id)
        ->where('products.data.0.inventory.status', 'in_stock'));
});

test('featured first retains every available featured product in stable main catalog order', function () {
    $products = Product::factory()->available()->count(6)->sequence(
        fn ($sequence): array => ['name' => sprintf('Curated %02d', 6 - $sequence->index)],
    )->create(['is_featured' => true]);
    $ordinary = Product::factory()->available()->create(['name' => 'Aardvark ordinary hardware']);

    $response = $this->get(route('products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('featured_products')->has('products.data', 7)->where('products.total', 7));
    expect(collect($response->inertiaProps('products.data'))->pluck('id')->all())
        ->toBe([$products[5]->id, $products[4]->id, $products[3]->id, $products[2]->id, $products[1]->id, $products[0]->id, $ordinary->id]);
});

test('main catalog featured priority is independent of personalization and leaves administrator selection unchanged', function () {
    $ordinary = Product::factory()->available()->create(['name' => 'Alpha ordinary hardware', 'is_featured' => false]);
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);

    $this->actingAs($customer)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->missing('featured_products')->has('products.data', 1)->where('products.data.0.is_featured', false));

    $featured = Product::factory()->available()->create(['name' => 'Zulu featured hardware', 'is_featured' => true]);
    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.id', $featured->id)->has('recommendations', 0));
    $this->assertDatabaseHas('products', ['id' => $ordinary->id, 'is_featured' => false]);
    $this->assertDatabaseHas('products', ['id' => $featured->id, 'is_featured' => true]);
});

test('search and discovery filters retain featured matches in the main catalog', function (string $filter) {
    $product = Product::factory()->available()->create(['is_featured' => true, 'brand' => 'Atlas']);
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);
    $value = match ($filter) {
        'q' => $product->name,
        'category_id' => $product->category_id,
        'brand' => $product->brand,
        'tag_id' => $tag->id,
    };

    $this->get(route('products.index', [$filter => $value, 'sort' => 'price_asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('featured_products')->has('recommendations', 0)->has('products.data', 1)
            ->where('products.data.0.id', $product->id)->where('products.data.0.is_featured', true));
})->with(['q', 'category_id', 'brand', 'tag_id']);

test('web sorting uses effective numeric prices including zero discounts and name ties', function (string $sort, array $expectedNames) {
    foreach ([
        ['name' => 'Zero discount', 'price' => '99.00', 'discount_price' => '0.00'],
        ['name' => 'Alpha equal', 'price' => '10.00'],
        ['name' => 'Zulu equal', 'price' => '100.00', 'discount_price' => '10.00'],
        ['name' => 'Featured expensive', 'price' => '9999999999.99', 'is_featured' => true],
        ['name' => 'Discounted midrange', 'price' => '500.00', 'discount_price' => '10.01'],
    ] as $attributes) {
        Product::factory()->available()->create($attributes);
    }

    $response = $this->get(route('products.index', ['sort' => $sort]));

    $response->assertInertia(fn (Assert $page) => $page->where('filters.sort', $sort));
    expect(collect($response->inertiaProps('products.data'))->pluck('name')->all())->toBe($expectedNames);
})->with([
    'ascending' => ['price_asc', ['Zero discount', 'Alpha equal', 'Zulu equal', 'Discounted midrange', 'Featured expensive']],
    'descending' => ['price_desc', ['Featured expensive', 'Discounted midrange', 'Alpha equal', 'Zulu equal', 'Zero discount']],
]);

test('web sorting preserves filters pagination links scroll metadata and mobile results', function (string $sort) {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->available()->count(13)->for($category)->sequence(
        fn ($sequence): array => ['name' => sprintf('Atlas Part %02d', $sequence->index + 1)],
    )->create(['brand' => 'Atlas', 'price' => '100.00']);
    $products->each(fn (Product $product) => $product->tags()->attach($tag));
    $discounted = Product::factory()->available()->for($category)->create([
        'name' => 'Zulu Part', 'brand' => 'Atlas', 'price' => '200.00', 'discount_price' => '50.00',
    ]);
    $discounted->tags()->attach($tag);
    Product::factory()->available()->create(['name' => 'Excluded Part', 'brand' => 'Other', 'price' => '1.00']);
    $filters = ['q' => 'Part', 'category_id' => $category->id, 'brand' => 'Atlas', 'tag_id' => $tag->id, 'sort' => $sort];

    $response = $this->get(route('products.index', $filters));
    $secondPage = $this->get($response->inertiaProps('products.next_page_url'));

    $response->assertInertia(fn (Assert $page) => $page->where('filters', $filters)->has('products.data', 12)->where('products.total', 14));
    $expectedIds = $sort === 'price_asc' ? [$discounted->id, ...$products->modelKeys()] : [...$products->modelKeys(), $discounted->id];
    expect([
        ...collect($response->inertiaProps('products.data'))->pluck('id')->all(),
        ...collect($secondPage->inertiaProps('products.data'))->pluck('id')->all(),
    ])->toBe($expectedIds);
    parse_str(parse_url($response->inertiaProps('products.next_page_url'), PHP_URL_QUERY), $nextQuery);
    expect($nextQuery)->toBe([...array_map(strval(...), $filters), 'page' => '2']);

    $mobile = $this->get(route('api.v1.products.index', $filters));
    expect(collect($mobile->json('data'))->pluck('id')->all())
        ->toBe(collect($response->inertiaProps('products.data'))->pluck('id')->all());
    $mobile->assertJsonMissingPath('featured_products')->assertJsonPath('data.0.inventory.quantity', 10);

    $this->withHeaders([
        'X-Inertia' => 'true', 'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index', 'X-Inertia-Partial-Data' => 'products',
    ])->get(route('products.index', [...$filters, 'page' => 2]))
        ->assertJsonCount(2, 'props.products.data')
        ->assertJsonPath('scrollProps.products.currentPage', 2)
        ->assertJsonMissingPath('props.featured_products');

    $this->withHeaders(['X-Inertia-Reset' => 'products'])
        ->get(route('products.index', [...$filters, 'sort' => $sort === 'price_asc' ? 'price_desc' : 'price_asc']))
        ->assertJsonPath('scrollProps.products.currentPage', 1)
        ->assertJsonPath('scrollProps.products.reset', true);
})->with(['price_asc', 'price_desc']);

test('sorting alone preserves recommendation data and defaults to featured first in the main grid', function (mixed $sort) {
    $featured = Product::factory()->available()->create(['name' => 'Zulu', 'price' => '100.00', 'is_featured' => true]);
    $ordinary = Product::factory()->available()->create(['name' => 'Alpha', 'price' => '1.00']);
    $this->get(route('products.index', ['sort' => $sort]))->assertInertia(fn (Assert $page) => $page
        ->where('filters.sort', $sort ?: 'featured')
        ->missing('featured_products')
        ->where('products.data.0.id', $sort === 'price_asc' ? $ordinary->id : $featured->id)
        ->loadDeferredProps('recommendations', fn (Assert $reload) => $reload
            ->where('recommendations.0.product.id', $featured->id)));
})->with(['omitted' => [null], 'blank' => [''], 'featured' => ['featured'], 'ascending' => ['price_asc'], 'descending' => ['price_desc']]);

test('invalid web sorting returns validation errors', function (mixed $sort) {
    $this->from(route('products.index'))->get(route('products.index', ['sort' => $sort]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors(['sort' => 'The selected sort is invalid.']);
})->with(['unsupported' => ['newest'], 'array' => [['price_asc']], 'unsafe' => ['price desc; DROP TABLE products']]);
