<?php

use App\Models\Product;
use App\Models\Tag;
use Database\Seeders\DevelopmentCatalogSeeder;

test('the default seed workflow creates representative development catalog scenarios', function () {
    $this->seed();

    $products = Product::query()
        ->with(['category', 'inventory', 'tags'])
        ->whereLike('name', '[DEMO]%')
        ->get()
        ->keyBy('name');

    $this->assertDatabaseCount('categories', 4);
    $this->assertDatabaseCount('products', 8);
    $this->assertDatabaseCount('tags', 7);
    $this->assertDatabaseCount('product_tag', 21);
    $this->assertDatabaseCount('inventories', 8);

    expect($products)->toHaveCount(8)
        ->and($products->pluck('brand')->unique()->sort()->values()->all())->toBe([
            'AMD',
            'Crucial',
            'Intel',
            'Keychron',
            'Logitech',
            'NVIDIA',
            'Samsung',
        ])
        ->and($products->pluck('image_path')->every(
            fn (string $imagePath): bool => str_starts_with($imagePath, 'images/demo-products/'),
        ))->toBeTrue()
        ->and($products->pluck('description')->unique()->all())->toBe([
            'Synthetic development sample only; not a Battlefront stocked product.',
        ]);

    $atlasGraphicsCard = $products['[DEMO] NVIDIA Atlas Graphics Card'];
    expect($atlasGraphicsCard->category->name)->toBe('[DEMO] Graphics Cards')
        ->and($atlasGraphicsCard->price)->toBe('39999.00')
        ->and($atlasGraphicsCard->discount_price)->toBe('36999.00')
        ->and($atlasGraphicsCard->is_featured)->toBeTrue()
        ->and($atlasGraphicsCard->image_path)->toBe('images/demo-products/graphics-card.png')
        ->and($atlasGraphicsCard->inventory->quantity)->toBe(8)
        ->and($atlasGraphicsCard->inventory->reorder_level)->toBe(3)
        ->and($atlasGraphicsCard->tags->pluck('name')->sort()->values()->all())->toBe([
            'Content Creation',
            'Gaming',
            'High Performance',
        ]);

    $outOfStockGraphicsCard = $products['[DEMO] AMD Nova Graphics Card'];
    expect($outOfStockGraphicsCard->inventory->quantity)->toBe(0)
        ->and($outOfStockGraphicsCard->is_active)->toBeTrue();

    $lowStockProcessor = $products['[DEMO] AMD Forge Processor'];
    expect($lowStockProcessor->inventory->quantity)->toBe(4)
        ->and($lowStockProcessor->inventory->reorder_level)->toBe(5);

    $inactiveStorageProduct = $products['[DEMO] Crucial Archive SATA SSD'];
    expect($inactiveStorageProduct->is_active)->toBeFalse()
        ->and(Product::active()->whereKey($inactiveStorageProduct)->doesntExist())->toBeTrue();
});

test('the default seed workflow can be rerun without duplicates and restores its scenarios', function () {
    $this->seed();
    $atlasGraphicsCard = Product::query()->where('name', '[DEMO] NVIDIA Atlas Graphics Card')->firstOrFail();
    $mechanicalTag = Tag::query()->where('name', 'Mechanical')->firstOrFail();
    $atlasGraphicsCard->update([
        'price' => '1.00',
        'discount_price' => null,
        'image_path' => 'products/not-a-seeded-image.png',
    ]);
    $atlasGraphicsCard->inventory()->update([
        'quantity' => 99,
        'reorder_level' => 98,
    ]);
    $atlasGraphicsCard->tags()->sync([$mechanicalTag->id]);

    $this->seed();

    $atlasGraphicsCard->refresh()->load(['inventory', 'tags']);
    $this->assertDatabaseCount('categories', 4);
    $this->assertDatabaseCount('products', 8);
    $this->assertDatabaseCount('tags', 7);
    $this->assertDatabaseCount('product_tag', 21);
    $this->assertDatabaseCount('inventories', 8);
    expect($atlasGraphicsCard->price)->toBe('39999.00')
        ->and($atlasGraphicsCard->discount_price)->toBe('36999.00')
        ->and($atlasGraphicsCard->image_path)->toBe('images/demo-products/graphics-card.png')
        ->and($atlasGraphicsCard->inventory->quantity)->toBe(8)
        ->and($atlasGraphicsCard->inventory->reorder_level)->toBe(3)
        ->and($atlasGraphicsCard->tags->pluck('name')->sort()->values()->all())->toBe([
            'Content Creation',
            'Gaming',
            'High Performance',
        ]);
});

test('development catalog image references resolve to local mock assets', function () {
    $this->seed();

    $imagePaths = Product::query()
        ->whereLike('name', '[DEMO]%')
        ->pluck('image_path')
        ->unique();

    expect($imagePaths)->toHaveCount(4);

    foreach ($imagePaths as $imagePath) {
        expect($imagePath)->toStartWith('images/demo-products/')
            ->and(public_path($imagePath))->toBeFile();
    }
});

test('development catalog records are not seeded outside local and testing environments', function () {
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn (): string => 'production');

        app(DevelopmentCatalogSeeder::class)->run();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }

    $this->assertDatabaseCount('categories', 0);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('tags', 0);
    $this->assertDatabaseCount('product_tag', 0);
    $this->assertDatabaseCount('inventories', 0);
});
