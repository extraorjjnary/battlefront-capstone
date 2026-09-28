<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;

test('recommendation inputs use eligible catalog relationships and live Sagay stock', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $inactiveCategory = Category::factory()->inactive()->create();
    $tag = Tag::factory()->create(['name' => 'Home Use']);
    $available = Product::factory()->for($category)->create([
        'brand' => null,
        'price' => '2500.00',
        'discount_price' => '2300.00',
    ]);
    $available->tags()->attach($tag);
    Inventory::factory()->for($available)->create(['quantity' => 3]);
    $outOfStock = Product::factory()->for($category)->create();
    Inventory::factory()->for($outOfStock)->create(['quantity' => 0]);
    $withoutInventory = Product::factory()->for($category)->create();
    Product::factory()->for($category)->inactive()->create();
    Product::factory()->for($inactiveCategory)->create();

    $products = app(ProductCatalogRepository::class)->recommendationInputs()->orderBy('id')->get();

    expect($products->modelKeys())->toBe([$available->id, $outOfStock->id, $withoutInventory->id]);
    expect($products->every(fn (Product $product): bool => $product->relationLoaded('category')
        && $product->relationLoaded('tags')
        && $product->relationLoaded('inventory')))->toBeTrue();
    expect($products[0]->brand)->toBeNull();
    expect($products[0]->category->name)->toBe('Networking');
    expect($products[0]->tags->pluck('name')->all())->toBe(['Home Use']);
    expect($products[0]->price)->toBe('2500.00');
    expect($products[0]->discount_price)->toBe('2300.00');
    expect($products[0]->inventory?->quantity)->toBe(3);
    expect($products[0]->getAttribute('is_available'))->toBeTrue();
    expect($products[1]->inventory?->quantity)->toBe(0);
    expect($products[1]->getAttribute('is_available'))->toBeFalse();
    expect($products[2]->inventory)->toBeNull();
    expect($products[2]->getAttribute('is_available'))->toBeFalse();
});
