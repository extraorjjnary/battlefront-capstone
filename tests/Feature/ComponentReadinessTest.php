<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use Database\Seeders\PcBuilderCatalogDraftSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('readiness queue distinguishes candidates from laptop memory and storage accessories', function () {
    $ram = Category::factory()->create(['name' => 'RAM']);
    $storage = Category::factory()->create(['name' => 'Storage']);
    $desktop = Product::factory()->for($ram)->create(['name' => 'Desktop memory']);
    Product::factory()->for($ram)->create(['name' => 'Laptop RAM SODIMM']);
    Product::factory()->for($storage)->create(['name' => 'Portable SSD enclosure']);
    Product::factory()->for($ram)->create(['name' => 'Inactive memory', 'is_active' => false]);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.component-specifications.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('candidates', 3)
        ->where('candidates.0.product_id', $desktop->id)
        ->where('candidates.0.status', 'needs_identity')
        ->where('candidates.1.status', 'not_core_component')
        ->where('candidates.2.status', 'not_core_component'));
    expect(ProductComponentProfile::query()->count())->toBe(0);
});

test('customers cannot access the readiness queue', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('administration.component-specifications.index'))->assertForbidden();
});

test('catalog preparation creates only matching drafts and preserves inventory and existing revisions', function () {
    $category = Category::factory()->create(['name' => 'Motherboard']);
    $product = Product::factory()->available()->for($category)->create(['name' => 'MSI B450M-A PRO MAX II']);
    $price = $product->price;
    $stock = $product->inventory->quantity;
    $this->seed(PcBuilderCatalogDraftSeeder::class);
    $profile = $product->componentProfile()->firstOrFail();
    expect($profile->review_status)->toBe('draft')
        ->and($profile->revisions()->first()->specifications['socket'])->toBe('AM4');
    $this->seed(PcBuilderCatalogDraftSeeder::class);
    expect($profile->revisions()->count())->toBe(1)
        ->and($product->refresh()->price)->toBe($price)
        ->and($product->inventory->quantity)->toBe($stock);
    $this->get(route('pc-builder.index'))->assertInertia(fn (Assert $page) => $page
        ->where('components.motherboard.0.specifications_reviewed', false)
        ->where('components.motherboard.0.specifications', [])
        ->has('templates', 0));
});

test('catalog preparation ignores wrong categories and production', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    Product::factory()->for($category)->create(['name' => 'MSI B450M-A PRO MAX II']);
    $this->seed(PcBuilderCatalogDraftSeeder::class);
    expect(ProductComponentProfile::query()->count())->toBe(0);
    Product::factory()->for($category)->create(['name' => 'GIGABYTE B450M DS3H V2']);
    app()->detectEnvironment(fn (): string => 'production');
    app(PcBuilderCatalogDraftSeeder::class)->run();
    expect(ProductComponentProfile::query()->count())->toBe(0);
});
