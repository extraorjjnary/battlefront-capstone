<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the product schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('products'))->toEqualCanonicalizing([
        'id',
        'name',
        'description',
        'category_id',
        'brand',
        'price',
        'is_featured',
        'discount_price',
        'image_path',
        'is_active',
        'created_at',
    ]);
});

test('a product persists with its approved defaults and casts', function () {
    $product = Product::factory()->create([
        'name' => 'GeForce RTX 5070',
        'description' => null,
        'brand' => 'NVIDIA',
        'price' => '38999.90',
        'discount_price' => null,
        'image_path' => null,
    ]);

    $this->assertModelExists($product);
    expect($product->name)->toBe('GeForce RTX 5070')
        ->and($product->description)->toBeNull()
        ->and($product->price)->toBe('38999.90')
        ->and($product->discount_price)->toBeNull()
        ->and($product->is_featured)->toBeFalse()
        ->and($product->is_featured)->toBeBool()
        ->and($product->is_active)->toBeTrue()
        ->and($product->is_active)->toBeBool()
        ->and($product->created_at)->not->toBeNull();
});

test('a product belongs to a required category', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    expect($product->category->is($category))->toBeTrue()
        ->and($category->products->sole()->is($product))->toBeTrue()
        ->and(fn () => $category->delete())->toThrow(QueryException::class);
});

test('required product fields cannot be omitted', function (string $missingField) {
    $attributes = [
        'name' => 'Wireless Mouse',
        'category_id' => Category::factory()->create()->id,
        'brand' => 'Logitech',
        'price' => '1499.00',
    ];
    unset($attributes[$missingField]);

    expect(fn () => Product::query()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'name' => 'name',
    'category' => 'category_id',
    'brand' => 'brand',
    'price' => 'price',
]);

test('the active scope retains inactive products and allows reactivation', function () {
    $activeProduct = Product::factory()->create();
    $inactiveProduct = Product::factory()->inactive()->create();

    expect(Product::active()->get())->toHaveCount(1)
        ->and(Product::active()->sole()->is($activeProduct))->toBeTrue()
        ->and(Product::query()->find($inactiveProduct->id))->not->toBeNull();

    $inactiveProduct->update(['is_active' => true]);

    expect(Product::active()->get())->toHaveCount(2)
        ->and($inactiveProduct->refresh()->is_active)->toBeTrue();
});

test('zero prices and lower non-negative discounts are accepted', function () {
    $category = Category::factory()->create();
    $freeProduct = Product::factory()->for($category)->create([
        'price' => '0.00',
        'discount_price' => null,
    ]);
    $discountedProduct = Product::factory()->for($category)->create([
        'price' => '100.00',
        'discount_price' => '0.00',
    ]);

    expect($freeProduct->price)->toBe('0.00')
        ->and($discountedProduct->discount_price)->toBe('0.00');
});

test('invalid monetary values are rejected', function (array $attributes) {
    expect(fn () => Product::factory()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'negative price' => [['price' => '-0.01', 'discount_price' => null]],
    'negative discount' => [['price' => '100.00', 'discount_price' => '-0.01']],
    'discount equal to price' => [['price' => '100.00', 'discount_price' => '100.00']],
    'discount above price' => [['price' => '100.00', 'discount_price' => '100.01']],
]);

test('a valid product cannot be updated with an invalid discount', function () {
    $product = Product::factory()->create([
        'price' => '100.00',
        'discount_price' => '80.00',
    ]);

    expect(fn () => $product->update(['discount_price' => '100.00']))
        ->toThrow(QueryException::class);
});
