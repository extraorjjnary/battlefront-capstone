<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;

test('finds arbitrary catalog products through supported attributes', function (string $message) {
    $category = Category::factory()->create(['name' => 'Field Networking']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Aurelius Link Station',
        'description' => 'Outdoor connectivity unit',
        'brand' => 'Helios Labs',
        'price' => '12999.00',
        'discount_price' => '11999.00',
    ]);
    $tags = Tag::factory()->count(2)->sequence(
        ['name' => 'Solar Ready'],
        ['name' => 'Enterprise'],
    )->create();
    $product->tags()->attach($tags);
    Inventory::factory()->for($product)->create(['quantity' => 7]);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context)->toBe([
        'products' => [[
            'name' => 'Aurelius Link Station',
            'description' => 'Outdoor connectivity unit',
            'brand' => 'Helios Labs',
            'category' => 'Field Networking',
            'tags' => ['Enterprise', 'Solar Ready'],
            'price' => '12999.00',
            'discount_price' => '11999.00',
            'inventory' => [
                'quantity' => 7,
                'status' => 'in_stock',
            ],
        ]],
    ]);
})->with([
    'name' => ['Do you have the Aurelius Link Station?'],
    'brand' => ['Are Helios Labs products available?'],
    'category' => ['Show Field Networking stock.'],
    'tag' => ['Do you have anything Solar Ready?'],
    'description' => ['I need an outdoor connectivity unit.'],
]);

test('reports zero and missing Sagay inventory without hiding eligible products', function () {
    $tag = Tag::factory()->create(['name' => 'Remote Kit']);
    $missingInventory = Product::factory()->create(['name' => 'Alpine Remote Kit']);
    $outOfStock = Product::factory()->create(['name' => 'Zephyr Remote Kit']);
    $missingInventory->tags()->attach($tag);
    $outOfStock->tags()->attach($tag);
    Inventory::factory()->for($outOfStock)->create(['quantity' => 0]);

    $context = app(ResolveProductContext::class)->execute('Is a Remote Kit available?');

    expect($context['products'])->toHaveCount(2)
        ->and($context['products'][0]['name'])->toBe('Alpine Remote Kit')
        ->and($context['products'][0]['inventory'])->toBe([
            'quantity' => null,
            'status' => 'unavailable',
        ])
        ->and($context['products'][1]['name'])->toBe('Zephyr Remote Kit')
        ->and($context['products'][1]['inventory'])->toBe([
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);
});

test('excludes inactive products and products in inactive categories', function () {
    $activeCategory = Category::factory()->create();
    $inactiveCategory = Category::factory()->inactive()->create();
    Product::factory()->inactive()->for($activeCategory)->create(['name' => 'Orchid Router']);
    Product::factory()->for($inactiveCategory)->create(['name' => 'Orchid Switch']);

    $context = app(ResolveProductContext::class)->execute('Is an Orchid product available?');

    expect($context)->toBe(['products' => []]);
});

test('returns explicit empty product context when no catalog term can be resolved', function (string $message) {
    Product::factory()->create(['name' => 'Known Catalog Item']);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context)->toBe(['products' => []]);
})->with([
    'empty input' => ['   ...   '],
    'only generic query words' => ['What products are available?'],
    'no catalog match' => ['Do you have Nebula Quantum Parts?'],
]);
