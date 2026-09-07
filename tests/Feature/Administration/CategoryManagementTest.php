<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing catalog categories', function () {
    $this->get(route('administration.categories.index'))
        ->assertRedirect(route('login'));
});

test('customers are forbidden from catalog category actions', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.categories.index'))
        ->assertForbidden();
    $this->actingAs($customer)
        ->post(route('administration.categories.store'), [
            'name' => 'Graphics cards',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('categories', [
        'name' => 'Graphics cards',
    ]);
});

test('administrators can view active and inactive categories in stable order', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    Product::factory()->for($processors)->count(2)->create();
    Category::factory()->inactive()->create(['name' => 'Cases']);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.categories.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Categories/Index')
        ->has('categories.data', 2)
        ->where('categories.data.0.name', 'Cases')
        ->where('categories.data.0.is_active', false)
        ->where('categories.data.1.name', 'Processors')
        ->where('categories.data.1.products_count', 2));
});

test('catalog categories are paginated in stable name order', function () {
    $administrator = User::factory()->administrator()->create();
    Category::factory()->count(16)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Category %02d', 16 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.categories.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('categories.data', 15)
        ->where('categories.total', 16)
        ->where('categories.last_page', 2)
        ->where('categories.data.0.name', 'Category 01')
        ->where('categories.data.14.name', 'Category 15'));
});

test('administrators can open category create and edit pages', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->inactive()->create([
        'name' => 'Graphics cards',
        'description' => 'Dedicated graphics hardware.',
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.categories.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Categories/Create'));
    $this->actingAs($administrator)
        ->get(route('administration.categories.edit', $category))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Categories/Edit')
            ->where('category.name', 'Graphics cards')
            ->where('category.description', 'Dedicated graphics hardware.')
            ->where('category.is_active', false));
});

test('administrators can create categories without changing the active default', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this
        ->actingAs($administrator)
        ->post(route('administration.categories.store'), [
            'name' => 'Graphics cards',
            'description' => 'Dedicated graphics hardware.',
            'is_active' => false,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.categories.index'));
    $this->assertDatabaseHas('categories', [
        'name' => 'Graphics cards',
        'description' => 'Dedicated graphics hardware.',
        'is_active' => true,
    ]);
});

test('category details require a unique name', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    Category::factory()->create(['name' => 'Processors']);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.categories.create'))
        ->post(route('administration.categories.store'), $payload);

    $response
        ->assertRedirect(route('administration.categories.create'))
        ->assertSessionHasErrors($errors);
})->with([
    'required name' => [
        ['description' => 'Missing its name.'],
        ['name' => 'Enter a category name.'],
    ],
    'unique name' => [
        ['name' => 'Processors'],
        ['name' => 'A category with this name already exists.'],
    ],
]);

test('administrators can update category details without changing activation', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->inactive()->create(['name' => 'Cases']);

    $response = $this
        ->actingAs($administrator)
        ->put(route('administration.categories.update', $category), [
            'name' => 'Computer cases',
            'description' => 'Desktop chassis and enclosures.',
            'is_active' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.categories.index'));
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Computer cases',
        'description' => 'Desktop chassis and enclosures.',
        'is_active' => false,
    ]);
});

test('administrators can update a category without changing its name', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create([
        'name' => 'Processors',
        'description' => 'Original description.',
    ]);

    $response = $this
        ->actingAs($administrator)
        ->put(route('administration.categories.update', $category), [
            'name' => 'Processors',
            'description' => 'Updated description.',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.categories.index'));
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Processors',
        'description' => 'Updated description.',
    ]);
});

test('administrators can deactivate and reactivate categories without changing products', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    $this->actingAs($administrator)
        ->from(route('administration.categories.index'))
        ->patch(route('administration.categories.activation.update', $category), [
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.categories.index'));

    expect($category->refresh()->is_active)->toBeFalse();
    $this->assertModelExists($product);
    expect($product->refresh()->category->is($category))->toBeTrue();

    $this->actingAs($administrator)
        ->from(route('administration.categories.index'))
        ->patch(route('administration.categories.activation.update', $category), [
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($category->refresh()->is_active)->toBeTrue();
});

test('category activation requires an explicit boolean state', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.categories.index'))
        ->patch(route('administration.categories.activation.update', $category));

    $response
        ->assertRedirect(route('administration.categories.index'))
        ->assertSessionHasErrors('is_active');
    expect($category->refresh()->is_active)->toBeTrue();
});
