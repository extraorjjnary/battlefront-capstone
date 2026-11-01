<?php

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearch;
use Inertia\Testing\AssertableInertia as Assert;

test('quoted phrases stay literal and contiguous on web and mobile with either search engine', function (bool $provider) {
    $match = Product::factory()->available()->create(['name' => 'Atlas wireless keyboard black edition', 'brand' => 'Atlas', 'description' => 'spill proof']);
    $reversed = Product::factory()->available()->create(['name' => 'Atlas keyboard wireless', 'brand' => 'Atlas', 'description' => 'spill proof']);
    $split = Product::factory()->available()->create(['name' => 'Atlas wireless', 'brand' => 'Atlas', 'description' => 'keyboard spill proof']);
    $typo = Product::factory()->available()->create(['name' => 'Atlas wireles keyboard', 'brand' => 'Atlas', 'description' => 'spill proof']);
    $missing = Product::factory()->available()->create(['name' => 'Atlas wireless keyboard', 'brand' => 'Atlas', 'description' => null]);
    $inactive = Product::factory()->available()->inactive()->create(['name' => 'Inactive wireless keyboard', 'brand' => 'Atlas', 'description' => 'spill proof']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with('"wireless keyboard" "spill proof" Atlas')
        ->andReturn($provider ? [$match->id, $reversed->id, $split->id, $typo->id, $missing->id, $inactive->id] : null);

    $filters = ['q' => 'Atlas "wireless keyboard" "spill proof"'];
    $this->get(route('products.index', $filters))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.total', 1)->where('products.data.0.id', $match->id));
    $this->getJson(route('api.v1.products.index', $filters))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
})->with([true, false]);

test('quoted fallback preserves aliases capacities and wildcard literals', function (string $phrase, string $name, string $otherName) {
    config(['scout.driver' => null]);
    $match = Product::factory()->available()->create(['name' => $name, 'brand' => null, 'description' => null]);
    Product::factory()->available()->create(['name' => $otherName, 'brand' => null, 'description' => null]);

    $this->getJson(route('api.v1.products.index', ['q' => '"'.$phrase.'"']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
})->with([
    ['solid state drive', 'Atlas Solid State Drive', 'Atlas SSD'],
    ['1 TB', 'Atlas 1 TB device', 'Atlas 1TB device'],
    ['50% ZX_42!', 'Atlas 50% ZX_42! device', 'Atlas 500 ZX142 device'],
]);

test('fallback matches all terms across public fields and treats wildcard characters literally', function (string $query, array $attributes, array $otherAttributes) {
    config(['scout.driver' => null]);
    $defaults = ['name' => 'Unrelated item', 'brand' => null, 'description' => null];
    $match = Product::factory()->available()->create(array_merge($defaults, $attributes));
    Product::factory()->available()->create(array_merge($defaults, ['name' => 'Other unrelated item'], $otherAttributes));
    Product::factory()->available()->inactive()->create(array_merge($defaults, ['name' => $query]));

    $this->get(route('products.index', ['q' => $query]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $match->id));
    $this->getJson(route('api.v1.products.index', ['q' => $query]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
})->with([
    'product code' => ['ZX-5600', ['product_code' => 'ZX-5600'], ['product_code' => 'ZX-5601']],
    'reordered fields' => ['5600 Atlas', ['name' => 'Model 5600', 'brand' => 'Atlas'], ['name' => 'Model 5601', 'brand' => 'Atlas']],
    'percent' => ['50%', ['name' => '50% edition'], ['name' => '500 edition']],
    'underscore' => ['ZX_42', ['product_code' => 'ZX_42'], ['product_code' => 'ZX142']],
    'escape character' => ['ZX!42', ['product_code' => 'ZX!42'], ['product_code' => 'ZX42']],
    'normalized capacity' => ['Atlas 1 TB', ['name' => 'Atlas 1TB device'], ['name' => 'Atlas 2TB device']],
]);

test('ordinary queries normalize whitespace and capacity before provider matching without changing model codes', function () {
    $match = Product::factory()->available()->create();
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->once()
        ->with('ZX-AB 1TB')->andReturn([$match->id]);

    $this->getJson(route('api.v1.products.index', ['q' => '  ZX-AB   1 TB  ']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
});

test('provider matches retain live eligibility sorting and pagination for both clients', function () {
    $products = Product::factory()->available()->count(13)->sequence(fn ($sequence): array => ['name' => sprintf('Part %02d', $sequence->index), 'price' => (string) (100 + $sequence->index)])->create();
    $inactive = Product::factory()->available()->inactive()->create();
    $categoryInactive = Product::factory()->available()->for(Category::factory()->inactive())->create();
    $stockless = Product::factory()->create();
    $deleted = Product::factory()->available()->create();
    $deleted->delete();
    $ids = [...array_reverse($products->modelKeys()), $inactive->id, $categoryInactive->id, $stockless->id, $deleted->id];
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with('prat')->andReturn($ids);

    $this->get(route('products.index', ['q' => 'prat', 'sort' => 'price_asc']))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 12)->where('products.total', 13)->where('products.data.0.id', $products[0]->id));
    $this->getJson(route('api.v1.products.index', ['q' => 'prat', 'sort' => 'price_asc', 'page' => 2]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 13)->assertJsonPath('data.0.id', $products[12]->id);
});

test('provider candidates still obey category brand tag and price filters', function () {
    $match = Product::factory()->available()->hasTags(1)->create(['brand' => 'Atlas', 'price' => '100.00', 'discount_price' => '0.00']);
    $excluded = Product::factory()->available()->create(['price' => '100.00']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with('typo')->andReturn([$excluded->id, $match->id]);
    $this->getJson(route('api.v1.products.index', ['q' => 'typo', 'category_ids' => [$match->category_id], 'brand' => 'Atlas', 'tag_id' => $match->tags->first()->id, 'min_price' => '0', 'max_price' => '0']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
});

test('database fallback retains text filtering and successful zero hits stay empty', function (?array $ids, int $count) {
    Product::factory()->available()->create(['name' => 'Ryzen']);
    Product::factory()->available()->create(['name' => 'Unrelated', 'brand' => null, 'description' => null]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with('Ryzen')->andReturn($ids);
    $this->getJson(route('api.v1.products.index', ['q' => 'Ryzen']))->assertOk()->assertJsonCount($count, 'data');
})->with([[null, 1], [[], 0]]);

test('blank browsing does not call the provider', function () {
    Product::factory()->available()->create();
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');
    $this->getJson(route('api.v1.products.index'))->assertOk()->assertJsonCount(1, 'data');
});

test('cpu searches return available processor products on web and mobile', function (string $query) {
    $category = Category::factory()->create(['name' => 'Processor']);
    $processor = Product::factory()->available()->for($category)->create(['name' => 'AMD Ryzen 5 5600', 'brand' => 'AMD', 'description' => null]);
    Product::factory()->available()->create(['name' => 'CPU cooler']);
    Product::factory()->available()->inactive()->for($category)->create();
    Product::factory()->for($category)->create();
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');

    $this->get(route('products.index', ['q' => $query]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $processor->id));
    $this->getJson(route('api.v1.products.index', ['q' => $query]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $processor->id);
    $this->getJson(route('api.v1.products.index', ['q' => $query, 'brand' => 'Intel']))->assertOk()
        ->assertJsonCount(0, 'data');
})->with(['CPU', 'cpu', ' Cpu ']);

test('storage and other broad part aliases browse their category on both clients', function (string $term, string $categoryName) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Component model']);
    Product::factory()->available()->create(['name' => $term.' accessory']);
    Product::factory()->for($category)->create();
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');

    $this->get(route('products.index', ['q' => $term]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $product->id));
    $this->getJson(route('api.v1.products.index', ['q' => $term]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
})->with([
    ['storage', 'Storage'], ['memory', 'RAM'], ['mobo', 'Motherboard'], ['gpu', 'Graphics Card'],
    ['psu', 'Power Supply'], ['chassis', 'PC Case'], ['cpu cooler', 'Cooling Components'], ['display', 'Monitor'],
]);

test('specific storage and capacity searches retain their text and category with either engine', function (?array $ids) {
    $category = Category::factory()->create(['name' => 'Storage']);
    $ssd = Product::factory()->available()->for($category)->create(['name' => 'SSD Atlas device 1TB', 'description' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Atlas HDD 1TB', 'description' => null, 'brand' => null]);
    $adapter = Product::factory()->available()->create(['name' => 'SSD 1TB adapter']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->with('ssd 1tb')->andReturn($ids === null ? null : [$ssd->id, $adapter->id]);

    $this->getJson(route('api.v1.products.index', ['q' => 'solid state drive 1TB']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ssd->id);
})->with(['database fallback' => [null], 'provider matches' => [[]]]);

test('wider catalog searches and corrected aliases retain their actual categories', function (string $term, string $categoryName, string $text) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Actual catalog model '.$text, 'brand' => null, 'description' => null]);
    Product::factory()->available()->create(['name' => 'Accessory '.$term, 'description' => null]);
    Product::factory()->for($category)->create(['name' => 'Unavailable '.$product->name]);
    $search = $this->mock(CatalogSearch::class);
    if ($text === '') {
        $search->shouldNotReceive('matchingIds');
    } else {
        $search->shouldReceive('matchingIds')->with($text)->andReturn(null);
    }

    $this->get(route('products.index', ['q' => $term]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)->where('products.data.0.id', $product->id));
    $this->getJson(route('api.v1.products.index', ['q' => $term]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
})->with([
    ['storag', 'Storage', ''], ['keybaord RGB', 'Peripherals', 'keyboard rgb'],
    ['proccessor AMD', 'Processor', 'amd'], ['laptop stnad', 'Laptop Bags & Stands', 'stand'],
    ['bundle', 'Bundles & Packages', ''], ['surveilance', 'CCTV & Security', ''],
    ['UPS', 'Power Accessories', 'ups'], ['printer ink', 'Printer', 'ink'],
    ['cleaning', 'Cleaning & Maintenance Supplies', ''], ['tv stick', 'Consumer Electronics', 'tv stick'],
    ['NodeMCU', 'DIY Electronics & Microcontrollers', 'nodemcu'], ['gaming chair', 'Furniture & Hardware Fixtures', 'chair'],
    ['bond paper', 'Office & Packaging Supplies', 'paper'], ['solar light', 'Solar & Lighting Products', 'solar'],
    ['piso wifi', 'Vending & Coin-Op Machine Parts', 'piso'], ['checkup', 'Service', ''],
]);

test('printer searches put stocked machines before featured consumables and cables', function (string $term) {
    $category = Category::factory()->create(['name' => 'Printer']);
    Product::factory()->available()->for($category)->count(13)->sequence(fn ($sequence): array => ['name' => 'Brother Ink '.sprintf('%02d', $sequence->index)])->create(['is_featured' => true]);
    Product::factory()->available()->for($category)->create(['name' => 'Printer Cable USB', 'is_featured' => true]);
    $printer = Product::factory()->available()->for($category)->create(['name' => 'PRINTER EPSON ECOTANK L3210 ALL-IN-ONE INK TANK']);
    Product::factory()->for($category)->create(['name' => 'PRINTER CANON OUT OF STOCK']);

    $this->get(route('products.index', ['q' => $term]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 12)->where('products.total', 15)->where('products.data.0.id', $printer->id));
    $this->getJson(route('api.v1.products.index', ['q' => $term]))->assertOk()
        ->assertJsonPath('meta.total', 15)->assertJsonPath('data.0.id', $printer->id);
})->with(['printer', 'printers', 'printing machine', 'prniter']);

test('explicit price sorting still takes priority over printer machine ordering', function () {
    $category = Category::factory()->create(['name' => 'Printer']);
    $ink = Product::factory()->available()->for($category)->create(['name' => 'Brother Ink Bottle', 'price' => '100.00']);
    $printer = Product::factory()->available()->for($category)->create(['name' => 'PRINTER EPSON', 'price' => '10000.00']);

    $this->getJson(route('api.v1.products.index', ['q' => 'printer', 'sort' => 'price_asc']))->assertOk()
        ->assertJsonPath('data.0.id', $ink->id);
    $this->getJson(route('api.v1.products.index', ['q' => 'printer', 'sort' => 'price_desc']))->assertOk()
        ->assertJsonPath('data.0.id', $printer->id);
});

test('device searches rank main products before featured accessories across the catalog', function (string $term, string $categoryName, string $mainName, string $accessoryName) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $main = Product::factory()->available()->for($category)->create(['name' => $mainName, 'price' => '100.00']);
    $accessory = Product::factory()->available()->for($category)->create(['name' => $accessoryName, 'price' => '200.00', 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([$accessory->id, $main->id]);

    $this->get(route('products.index', ['q' => $term]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 2)->where('products.data.0.id', $main->id));
    $this->getJson(route('api.v1.products.index', ['q' => $term]))->assertOk()
        ->assertJsonPath('data.0.id', $main->id)->assertJsonPath('meta.total', 2);
    $this->getJson(route('api.v1.products.index', ['q' => $term, 'sort' => 'price_desc']))->assertOk()
        ->assertJsonPath('data.0.id', $accessory->id);
})->with([
    ['storage', 'Storage', 'SSD DATO DS700 256GB', 'M.2 NVME SSD ENCLOSURE'],
    ['SSD', 'Storage', 'SSD ESGAMING 1TB', 'PORTABLE SSD ENCLOSURE'],
    ['CCTV', 'CCTV & Security', 'HIKVISION BULLET CAMERA 2MP', '8MP VIDEO BALUN'],
    ['networking', 'Networking', 'TP-LINK ROUTER ARCHER C54', 'ADAPTOR FOR MODEM'],
    ['mouse', 'Peripherals', 'RAPOO WIRELESS MOUSE', 'Extended Mousepad RGB'],
    ['monitor', 'Monitor', 'ACER MONITOR NO POWER CORD NO HDMI', 'TV BRACKET WALL MOUNT'],
    ['UPS', 'Power Accessories', 'UPS SECURE 650VA', 'RECHARGEABLE BATTERY FOR UPS'],
    ['cooling', 'Cooling Components', 'CPU FAN KEYTECH', 'DEEPCOOL THERMAL PASTE'],
    ['Epson printer', 'Printer', 'PRINTER EPSON ECOTANK INK TANK', 'Epson Ink Bottle'],
]);

test('explicit accessory and long phrase searches keep their requested products', function (string $term, string $categoryName, string $mainName, string $otherName) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $main = Product::factory()->available()->for($category)->create(['name' => $mainName, 'brand' => null, 'description' => null]);
    Product::factory()->available()->for($category)->create(['name' => $otherName, 'brand' => null, 'description' => null, 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn(null);

    $this->getJson(route('api.v1.products.index', ['q' => $term]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $main->id);
})->with([
    ['I need a case for SSD', 'Storage', 'M.2 SSD ENCLOSURE', 'SSD LEXAR 120GB'],
    ['replacement battery for UPS', 'Power Accessories', 'REPLACEMENT VRLA BATTERY FOR UPS', 'UPS AWP 1000VA'],
    ['TV wall mount', 'Monitor', 'TV BRACKET WALL MOUNT', 'SMART TV NATIONAL STAR'],
    ['please show me a wireless keyboard with RGB', 'Peripherals', 'RGB WIRELESS KEYBOARD', 'RGB MOUSEPAD'],
    ['looking for a 1 TB solid state drive', 'Storage', 'SSD ESGAMING 1TB 2.5 SATA', 'HDD SEAGATE 1TB'],
]);

test('storage search includes explicitly named storage products filed under surveillance', function () {
    $storage = Category::factory()->create(['name' => 'Storage']);
    $security = Category::factory()->create(['name' => 'CCTV & Security']);
    $ssd = Product::factory()->available()->for($storage)->create(['name' => 'SSD LEXAR 120GB']);
    $hdd = Product::factory()->available()->for($security)->create(['name' => 'SURVEILLANCE HDD 2TB SEAGATE']);
    Product::factory()->available()->for($security)->create(['name' => 'HIKVISION CAMERA']);
    Product::factory()->for($security)->create(['name' => 'SD CARD HIKSEMI OUT OF STOCK']);

    $this->getJson(route('api.v1.products.index', ['q' => 'storage']))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(route('api.v1.products.index', ['q' => 'storage', 'category_id' => $storage->id]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ssd->id);
    $this->getJson(route('api.v1.products.index', ['q' => 'storage', 'category_id' => $security->id]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $hdd->id);
});
