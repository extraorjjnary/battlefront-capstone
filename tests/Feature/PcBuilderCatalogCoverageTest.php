<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\PcBuilder\PcBuilderService;
use Database\Seeders\PcBuilderCatalogDraftSeeder;

test('catalog backfill covers every builder slot without creating products or stock', function () {
    $examples = [
        ['PROCESSOR AMD RYZEN™ 5 5600X (6CORES 12THREADS)', 'Processor', 'processor'],
        ['MSI B450M-A PRO MAX II', 'Motherboard', 'motherboard'],
        ['FASPEED RAM DDR4 3200MHZ (8GB - ZS4-8G-PAB)', 'RAM', 'memory'],
        ['SSD DATO DS700 256GB', 'Storage', 'storage'],
        ['Inplay Ultra Series GS650 PSU 80+ Bronze', 'Power Supply', 'power_supply'],
        ['CPU CASE KEYTECH ROBIN MINI (BLACK)', 'PC Case', 'case'],
        ['Biostar EXtreme Gaming GTX 1660 Super', 'Graphics Card', 'graphics_card'],
        ['CPU FAN KEYTECH TORNADO SINGLE BLACK', 'Cooling Components', 'cooler'],
        ['Computer Fan Big 120mm (SATA)', 'Cooling Components', 'fan'],
        ['Comfast Wifi Dongle CF-WU710N V5 (150MBPS)', 'Networking', 'network_adapter'],
        ['MONITOR ASUS TUF VG27AQ5A 210HZ', 'Monitor', 'monitor'],
        ['RAPOO WIRED USB KEYBOARD NK2600', 'Peripherals', 'keyboard'],
        ['RAPOO WIRED OPTICAL USB MOUSE N100', 'Peripherals', 'mouse'],
        ['RAPOO USB STEREO HEADSET H120', 'Audio Equipment', 'headset'],
        ['SPEAKER BOSSTON EK-01', 'Audio Equipment', 'speakers'],
        ['RAPOO WEB CAMERA 1080P FULL HD USB C260', 'Peripherals', 'webcam'],
        ['UPS AWP 1000VA 600W AID1000 ECO', 'Power Accessories', 'ups_avr'],
    ];
    $products = [];
    foreach ($examples as [$name, $categoryName, $type]) {
        $category = Category::query()->firstOrCreate(['name' => $categoryName], ['is_active' => true]);
        $products[$type] = Product::factory()->available()->for($category)->create(['name' => $name]);
    }
    $inventoryBefore = Inventory::query()->orderBy('product_id')->pluck('quantity', 'product_id')->all();
    $productIds = Product::query()->orderBy('id')->pluck('id')->all();

    $this->seed(PcBuilderCatalogDraftSeeder::class);
    $this->seed(PcBuilderCatalogDraftSeeder::class);

    $this->assertDatabaseCount('product_component_profiles', 17);
    expect(Product::query()->orderBy('id')->pluck('id')->all())->toBe($productIds)
        ->and(Inventory::query()->orderBy('product_id')->pluck('quantity', 'product_id')->all())->toBe($inventoryBefore)
        ->and(ProductComponentProfile::query()->where('review_status', 'approved')->exists())->toBeFalse();
    $catalog = app(PcBuilderService::class)->catalog();
    foreach ($products as $type => $product) {
        expect($catalog[$type][0]['id'])->toBe($product->id)
            ->and($catalog[$type][0]['specifications_reviewed'])->toBeFalse()
            ->and($catalog[$type][0]['specifications'])->toBe([]);
    }
    $cpu = $products['processor']->componentProfile()->with('revisions')->firstOrFail();
    expect($cpu->revisions->first()->specifications)->toMatchArray(['socket' => 'AM4', 'integrated_graphics' => false])
        ->and($cpu->revisions->first()->specifications)->not->toHaveKeys(['maximum_power_w', 'included_cooler'])
        ->and($cpu->revisions->first()->evidence[0]['url'])->toStartWith('https://www.amd.com/');
});

test('catalog backfill excludes unrelated items and preserves existing reviewed profiles', function () {
    $cooling = Category::factory()->create(['name' => 'Cooling Components']);
    $excluded = Product::factory()->available()->for($cooling)->create(['name' => 'DEEP COOL X6 HIGH PERFORMANCE LAPTOP COOLER']);
    $product = Product::factory()->available()->for($cooling)->create(['name' => 'CPU FAN KEYTECH TORNADO SINGLE BLACK']);
    $profile = ProductComponentProfile::factory()->for($product)->create(['component_type' => 'cooler', 'review_status' => 'approved', 'manufacturer_part_number' => 'Reviewed stock']);
    $before = $profile->getAttributes();

    $this->seed(PcBuilderCatalogDraftSeeder::class);

    expect($excluded->componentProfile()->exists())->toBeFalse()
        ->and($profile->refresh()->getAttributes())->toEqual($before);
});

test('catalog backfill respects catalog categories and never runs in production', function () {
    $category = Category::factory()->create(['name' => 'Peripherals']);
    Product::factory()->available()->for($category)->create(['name' => 'CPU FAN KEYTECH TORNADO SINGLE BLACK']);
    $this->seed(PcBuilderCatalogDraftSeeder::class);
    $this->assertDatabaseCount('product_component_profiles', 0);

    app()->detectEnvironment(fn (): string => 'production');
    Product::factory()->available()->for($category)->create(['name' => 'RAPOO WEB CAMERA 1080P FULL HD USB C260']);
    app(PcBuilderCatalogDraftSeeder::class)->run();
    $this->assertDatabaseCount('product_component_profiles', 0);
});
