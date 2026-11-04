<?php

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearch;
use Inertia\Testing\AssertableInertia as Assert;

test('reviewed category searches resolve to live products across web and mobile', function (string $categoryName) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Reviewed '.strtolower($categoryName).' catalog result']);
    Product::factory()->available()->create(['name' => 'Unrelated benchmark accessory']);
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');

    $this->get(route('products.index', ['q' => $categoryName]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $product->id));
    $this->getJson(route('api.v1.products.index', ['q' => $categoryName]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
})->with([
    'Audio Equipment', 'Bundles & Packages', 'Cables & Adapters', 'CCTV & Security',
    'Cleaning & Maintenance Supplies', 'Consumer Electronics', 'Cooling Components',
    'DIY Electronics & Microcontrollers', 'Furniture & Hardware Fixtures', 'Graphics Card',
    'Laptop Bags & Stands', 'Laptops & Desktops', 'Monitor', 'Motherboard', 'Networking',
    'Office & Packaging Supplies', 'PC Case', 'Peripherals', 'Power Accessories',
    'Power Supply', 'Printer', 'Processor', 'RAM', 'Service', 'Solar & Lighting Products',
    'Storage', 'Vending & Coin-Op Machine Parts',
]);

test('reviewed typo and long phrase searches preserve provider candidates and independently filter SQL fallback', function (string $query, string $categoryName, string $matchingText) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $match = Product::factory()->available()->for($category)->create(['name' => 'Reviewed '.strtoupper($matchingText).' catalog result', 'description' => null]);
    $other = Product::factory()->available()->for($category)->create(['name' => 'Unrelated catalog result', 'description' => null, 'brand' => null]);

    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with($matchingText)->andReturn([$match->id, $other->id]);
    $providerResponse = $this->getJson(route('api.v1.products.index', ['q' => $query]))->assertOk();
    expect(array_column($providerResponse->json('data'), 'id'))->toEqualCanonicalizing([$match->id, $other->id]);

    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with($matchingText)->andReturn(null);
    $fallbackResponse = $this->getJson(route('api.v1.products.index', ['q' => $query]))->assertOk();
    expect($fallbackResponse->json('data'))->toHaveCount(1)
        ->and($fallbackResponse->json('data.0.id'))->toBe($match->id);
})->with([
    ['proccessor AMD', 'Processor', 'amd'],
    ['Epson printer', 'Printer', 'epson'],
    ['looking for a 1 TB solid state drive', 'Storage', 'ssd 1tb'],
    ['wireles keybaord RGB', 'Peripherals', 'wireless keyboard rgb'],
]);
