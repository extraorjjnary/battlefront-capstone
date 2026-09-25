<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use Database\Seeders\DevelopmentCatalogSeeder;

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
    Inventory::factory()->for($product)->create(['quantity' => 7, 'reorder_level' => 2]);

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
            'is_demo' => false,
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
    'partial name' => ['Looking for Link Station.'],
    'multiple attributes' => ['Do you have Helios Field Networking products?'],
    'case and punctuation' => ['  ARE HELIOS-LABS products available?!  '],
]);

test('resolves natural brand and category questions against seeded demo catalog records', function (string $message) {
    $this->seed(DevelopmentCatalogSeeder::class);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context['products'])->toHaveCount(1)
        ->and($context['products'][0]['name'])->toBe('[DEMO] Samsung Sprint NVMe SSD')
        ->and($context['products'][0]['price'])->toBe('4599.00')
        ->and($context['products'][0]['is_demo'])->toBeTrue()
        ->and($context['products'][0]['demo_notice'])->toBe('Demo item only; listed prices are samples and Battlefront stock is unconfirmed.')
        ->and($context['products'][0]['inventory'])->toBe([
            'quantity' => null,
            'status' => 'unavailable',
        ]);
})->with([
    'brand' => ['Do you have Samsung product available currently?'],
    'category' => ['Do you have product for storage?'],
]);

test('prioritizes exact and field phrase matches before weaker description matches', function () {
    $category = Category::factory()->create(['name' => 'Components']);
    Product::factory()->for($category)->create([
        'name' => 'Alpha Device',
        'description' => 'Nova Station accessory',
        'brand' => 'Acme',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Aardvark Nova Station Cable',
        'description' => null,
        'brand' => 'Acme',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Nova Station',
        'description' => null,
        'brand' => 'Acme',
    ]);

    $context = app(ResolveProductContext::class)->execute('Do you have Nova Station currently?');

    expect(array_column($context['products'], 'name'))->toBe([
        'Nova Station',
        'Aardvark Nova Station Cable',
        'Alpha Device',
    ]);
});

test('matches every meaningful term across fields without returning partial distractors', function () {
    $networking = Category::factory()->create(['name' => 'Field Networking']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    Product::factory()->for($peripherals)->create([
        'name' => 'Helios Mouse',
        'description' => null,
        'brand' => 'Helios Labs',
    ]);
    Product::factory()->for($networking)->create([
        'name' => 'Aurelius Link Station',
        'description' => null,
        'brand' => 'Helios Labs',
    ]);
    Product::factory()->for($networking)->create([
        'name' => 'Aurelius Router',
        'description' => null,
        'brand' => 'Other Brand',
    ]);

    $context = app(ResolveProductContext::class)->execute('Do you have a Helios Link Station?');

    expect(array_column($context['products'], 'name'))->toBe(['Aurelius Link Station']);
});

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

test('reports positive inventory below the reorder level as low stock', function () {
    $product = Product::factory()->create(['name' => 'Aurelius Low Stock Router']);
    Inventory::factory()->for($product)->create(['quantity' => 1, 'reorder_level' => 2]);

    $context = app(ResolveProductContext::class)->execute('Aurelius Low Stock Router');

    expect($context['products'][0]['inventory'])->toBe([
        'quantity' => 1,
        'status' => 'low_stock',
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
    'natural generic query words' => ['Do you have products available currently in stock?'],
    'generic named inquiry' => ['Are you selling a product named?'],
    'no catalog match' => ['Do you have Nebula Quantum Parts?'],
]);
