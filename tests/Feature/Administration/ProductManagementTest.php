<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing catalog products', function () {
    $this->get(route('administration.products.index'))
        ->assertRedirect(route('login'));
});

test('customers are forbidden from catalog product actions', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.products.index'))
        ->assertForbidden();
    $this->actingAs($customer)
        ->post(route('administration.products.store'), [
            'name' => 'Unauthorized product',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'name' => 'Unauthorized product',
    ]);
});

test('administrators can view active and inactive products with catalog relationships', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($processors)->create([
        'name' => 'AMD Ryzen 7 9700X',
        'brand' => 'AMD',
        'price' => '21999.00',
        'discount_price' => '19999.00',
        'is_featured' => true,
    ]);
    $product->tags()->attach($gaming);
    Product::factory()->inactive()->create(['name' => 'Legacy processor']);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Products/Index')
        ->has('products.data', 2)
        ->where('products.data.0.name', 'AMD Ryzen 7 9700X')
        ->where('products.data.0.category.name', 'Processors')
        ->where('products.data.0.tags.0.name', 'Gaming')
        ->where('products.data.0.price', '21999.00')
        ->where('products.data.0.discount_price', '19999.00')
        ->where('products.data.0.is_featured', true)
        ->where('products.data.1.name', 'Legacy processor')
        ->where('products.data.1.is_active', false));
});

test('catalog products are paginated in stable name order', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()->count(13)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Product %02d', 13 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 12)
        ->where('products.total', 13)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.11.name', 'Product 12'));
});

test('administrators can search and filter catalog records', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    $graphicsCards = Category::factory()->create(['name' => 'Graphics cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $target = Product::factory()->for($processors)->inactive()->create([
        'name' => 'Ryzen workstation processor',
        'description' => 'Zen architecture for demanding workloads.',
        'brand' => 'AMD',
    ]);
    $target->tags()->attach($gaming);
    Product::factory()->for($processors)->inactive()->create([
        'name' => 'Different processor',
        'brand' => 'Intel',
    ]);
    Product::factory()->for($graphicsCards)->inactive()->create([
        'name' => 'Zen graphics card',
        'brand' => 'AMD',
    ]);
    Product::factory()->for($processors)->create([
        'name' => 'Active Zen processor',
        'brand' => 'AMD',
    ])->tags()->attach($gaming);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index', [
            'q' => 'Zen',
            'category_id' => $processors->id,
            'brand' => 'AMD',
            'tag_id' => $gaming->id,
            'status' => 'inactive',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'Zen')
        ->where('filters.category_id', $processors->id)
        ->where('filters.brand', 'AMD')
        ->where('filters.tag_id', $gaming->id)
        ->where('filters.status', 'inactive')
        ->has('products.data', 1)
        ->where('products.data.0.id', $target->id)
        ->where('filter_options.categories.1.name', 'Processors')
        ->where('filter_options.brands.0', 'AMD')
        ->where('filter_options.tags.0.name', 'Gaming'));
});

test('catalog pagination preserves the active administration query', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->count(13)->for($category)->create([
        'name' => 'Matching catalog product',
        'brand' => 'Battlefront',
        'is_active' => true,
    ]);

    $products->each(fn (Product $product) => $product->tags()->attach($tag));

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index', [
            'q' => 'Matching',
            'category_id' => $category->id,
            'brand' => 'Battlefront',
            'tag_id' => $tag->id,
            'status' => 'active',
        ]));

    $nextPageUrl = $response->inertiaProps('products.next_page_url');

    expect($nextPageUrl)->toContain('page=2')
        ->and($nextPageUrl)->toContain('q=Matching')
        ->and($nextPageUrl)->toContain("category_id={$category->id}")
        ->and($nextPageUrl)->toContain('brand=Battlefront')
        ->and($nextPageUrl)->toContain("tag_id={$tag->id}")
        ->and($nextPageUrl)->toContain('status=active');
});

test('invalid administration catalog filters are rejected', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->from(route('administration.products.index'))
        ->get(route('administration.products.index', [
            'category_id' => PHP_INT_MAX,
            'status' => 'archived',
            'page' => 0,
        ]))
        ->assertRedirect(route('administration.products.index'))
        ->assertSessionHasErrors(['category_id', 'status', 'page']);
});

test('administrators can open product forms with approved category and tag options', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->inactive()->create(['name' => 'Graphics cards']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($category)->inactive()->create([
        'name' => 'Radeon RX 9070',
    ]);
    $product->tags()->attach($tag);

    $this->actingAs($administrator)
        ->get(route('administration.products.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Products/Create')
            ->where('categories.0.name', 'Graphics cards')
            ->where('categories.0.is_active', false)
            ->where('tags.0.name', 'Gaming'));
    $this->actingAs($administrator)
        ->get(route('administration.products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Products/Edit')
            ->where('product.name', 'Radeon RX 9070')
            ->where('product.is_active', false)
            ->where('product.tag_ids.0', $tag->id));
});

test('administrators can create products and attach validated tags', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $response = $this
        ->actingAs($administrator)
        ->post(route('administration.products.store'), [
            'name' => 'GeForce RTX 5070',
            'description' => 'A graphics card for modern games.',
            'category_id' => $category->id,
            'brand' => 'NVIDIA',
            'price' => '39999.00',
            'discount_price' => '37999.50',
            'image_url' => 'https://example.com/rtx-5070.jpg',
            'is_featured' => true,
            'is_active' => false,
            'tag_ids' => $tags->pluck('id')->all(),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));
    $product = Product::query()->where('name', 'GeForce RTX 5070')->firstOrFail();
    expect($product->is_active)->toBeTrue()
        ->and($product->inventory)->toBeNull()
        ->and($product->tags->modelKeys())->toEqualCanonicalizing($tags->modelKeys());
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'category_id' => $category->id,
        'brand' => 'NVIDIA',
        'price' => '39999.00',
        'discount_price' => '37999.50',
        'is_featured' => true,
        'is_active' => true,
    ]);
});

test('product details reject invalid catalog values', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $validPayload = [
        'name' => 'GeForce RTX 5070',
        'category_id' => $category->id,
        'brand' => 'NVIDIA',
        'price' => '39999.00',
        'is_featured' => false,
    ];

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.products.create'))
        ->post(
            route('administration.products.store'),
            [...$validPayload, ...$payload],
        );

    $response
        ->assertRedirect(route('administration.products.create'))
        ->assertSessionHasErrors($errors);
    $this->assertDatabaseMissing('products', [
        'name' => 'GeForce RTX 5070',
    ]);
})->with([
    'required fields' => [
        [
            'name' => null,
            'category_id' => null,
            'brand' => null,
            'price' => null,
            'is_featured' => null,
        ],
        [
            'name' => 'Enter a product name.',
            'category_id' => 'Select a category.',
            'brand' => 'Enter the product brand.',
            'price' => 'Enter the regular price.',
            'is_featured' => 'Choose whether this is a featured product.',
        ],
    ],
    'unknown relationships' => [
        ['category_id' => PHP_INT_MAX, 'tag_ids' => [PHP_INT_MAX]],
        [
            'category_id' => 'Select a valid category.',
            'tag_ids.0' => 'Select valid product tags.',
        ],
    ],
    'invalid prices' => [
        ['price' => '-1.00', 'discount_price' => '40000.00'],
        [
            'price' => 'The regular price must be zero or greater.',
            'discount_price' => 'The discount price must be lower than the regular price.',
        ],
    ],
    'unsupported image scheme' => [
        ['image_url' => 'ftp://example.com/product.jpg'],
        ['image_url' => 'Enter a valid HTTP or HTTPS image URL.'],
    ],
    'duplicate tags' => [
        ['tag_ids' => [1, 1]],
        ['tag_ids.0' => 'Each tag may only be selected once.'],
    ],
]);

test('administrators can update product details and synchronize tags', function () {
    $administrator = User::factory()->administrator()->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->inactive()->create();
    $oldTag = Tag::factory()->create();
    $newTag = Tag::factory()->create();
    $product = Product::factory()->for($oldCategory)->inactive()->create([
        'name' => 'Old product name',
    ]);
    $product->tags()->attach($oldTag);

    $response = $this
        ->actingAs($administrator)
        ->put(route('administration.products.update', $product), [
            'name' => 'Updated product name',
            'description' => null,
            'category_id' => $newCategory->id,
            'brand' => 'Updated brand',
            'price' => '10000.00',
            'discount_price' => null,
            'image_url' => null,
            'is_featured' => false,
            'is_active' => true,
            'tag_ids' => [$newTag->id],
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));
    $product->refresh()->load('tags');
    expect($product->name)->toBe('Updated product name')
        ->and($product->category->is($newCategory))->toBeTrue()
        ->and($product->is_active)->toBeFalse()
        ->and($product->tags->modelKeys())->toBe([$newTag->id]);
    $this->assertDatabaseMissing('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $oldTag->id,
    ]);
});

test('an invalid product relationship leaves details and tags unchanged', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->for($category)->create([
        'name' => 'Original product',
        'price' => '10000.00',
    ]);
    $product->tags()->attach($tag);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.products.edit', $product))
        ->put(route('administration.products.update', $product), [
            'name' => 'Changed product',
            'category_id' => $category->id,
            'brand' => $product->brand,
            'price' => '12000.00',
            'is_featured' => false,
            'tag_ids' => [PHP_INT_MAX],
        ]);

    $response
        ->assertRedirect(route('administration.products.edit', $product))
        ->assertSessionHasErrors('tag_ids.0');
    $product->refresh()->load('tags');
    expect($product->name)->toBe('Original product')
        ->and($product->price)->toBe('10000.00')
        ->and($product->tags->modelKeys())->toBe([$tag->id]);
});

test('administrators can deactivate and reactivate products without changing relationships', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->for($category)->create();
    $product->tags()->attach($tag);
    $inventory = Inventory::factory()->for($product)->create();

    $this->actingAs($administrator)
        ->from(route('administration.products.index'))
        ->patch(route('administration.products.activation.update', $product), [
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));

    $product->refresh()->load(['category', 'tags', 'inventory']);
    expect($product->is_active)->toBeFalse()
        ->and($product->category->is($category))->toBeTrue()
        ->and($product->tags->sole()->is($tag))->toBeTrue()
        ->and($product->inventory->is($inventory))->toBeTrue();

    $this->actingAs($administrator)
        ->patch(route('administration.products.activation.update', $product), [
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->is_active)->toBeTrue();
});
