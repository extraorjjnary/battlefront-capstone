<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\LegacyQuarterlySalesFixtures;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function quarterlySalesService(): QuarterlySalesAggregationService
{
    return app(QuarterlySalesAggregationService::class);
}

function quarterlySale(Product $product, string $date, int $quantity = 1, string $price = '0.10'): Sale
{
    $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => bcmul($price, (string) $quantity, 2)]);
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => $quantity, 'price_at_time' => $price]);

    return $sale;
}

test('product history preserves known quantities and exact purchase-time revenue', function (string $code, array $quantities, array $revenues) {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $product = Product::where('product_code', 'DEVHIST40'.$code)->sole();

    $result = quarterlySalesService()->completedProducts(8, [$product->id]);

    expect($product->is_active)->toBeFalse();
    expect($result['start'])->toBe('2024-10-01');
    expect($result['end_exclusive'])->toBe('2026-10-01');
    expect($result['dimension'])->toBe('product');
    expect($result['timezone'])->toBe('UTC');
    expect($result['series'])->toHaveCount(1);
    expect($result['series'][0]['entity_id'])->toBe($product->id);
    $quarters = $result['series'][0]['quarters'];
    expect(array_column($quarters, 'quantity_sold'))->toBe($quantities);
    expect(array_column($quarters, 'item_revenue'))->toBe($revenues);
    expect(array_column($quarters, 'start'))->toBe(['2024-10-01', '2025-01-01', '2025-04-01', '2025-07-01', '2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01']);
    expect(array_column($quarters, 'quarter'))->toBe([4, 1, 2, 3, 4, 1, 2, 3]);
})->with([
    'stable' => ['STABLE', array_fill(0, 8, 10), array_fill(0, 8, '10000.00')],
    'increasing' => ['INCREASING', [5, 10, 15, 20, 25, 30, 35, 40], ['10000.00', '20000.00', '30000.00', '40000.00', '50000.00', '60000.00', '70000.00', '80000.00']],
    'declining' => ['DECLINING', [40, 35, 30, 25, 20, 15, 10, 5], ['60000.00', '52500.00', '45000.00', '37500.00', '30000.00', '22500.00', '15000.00', '7500.00']],
    'repeating' => ['REPEATING', [4, 8, 12, 20, 4, 8, 12, 20], ['2000.00', '4000.00', '6000.00', '10000.00', '2000.00', '4000.00', '6000.00', '10000.00']],
    'sparse' => ['SPARSE', [0, 0, 3, 0, 0, 6, 0, 9], ['0.00', '0.00', '7500.00', '0.00', '0.00', '15000.00', '0.00', '22500.00']],
    'price snapshots' => ['PRICE', array_fill(0, 8, 4), ['400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00']],
    'no history' => ['NOHISTORY', array_fill(0, 8, 0), array_fill(0, 8, '0.00')],
]);

test('category history combines products without duplicating order revenue', function () {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $components = Category::where('name', 'Historical Sales Components')->sole();
    $peripherals = Category::where('name', 'Historical Sales Peripherals')->sole();

    $result = quarterlySalesService()->completedCategories(8);

    $series = collect($result['series'])->keyBy('entity_id');
    expect(array_column($series[$components->id]['quarters'], 'quantity_sold'))->toBe(array_fill(0, 8, 55));
    expect(array_column($series[$components->id]['quarters'], 'item_revenue'))->toBe(['80000.00', '82500.00', '85000.00', '87500.00', '90000.00', '92500.00', '95000.00', '97500.00']);
    expect(array_column($series[$peripherals->id]['quarters'], 'quantity_sold'))->toBe([8, 12, 19, 24, 8, 18, 16, 33]);
    expect(array_column($series[$peripherals->id]['quarters'], 'item_revenue'))->toBe(['2400.00', '4400.00', '14000.00', '10500.00', '2600.00', '19600.00', '6700.00', '33200.00']);
    $buckets = $series->flatMap(fn (array $entity): array => $entity['quarters']);
    expect($buckets->sum('quantity_sold'))->toBe(578);
    expect($buckets->reduce(fn (string $total, array $bucket): string => bcadd($total, $bucket['item_revenue'], 2), '0.00'))->toBe('803400.00');
});

test('unfiltered history discovers only matching entities and explicit IDs retain zero series', function (string $method, string $model) {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $empty = $model === Product::class
        ? Product::where('product_code', 'DEVHIST40NOHISTORY')->sole()
        : Category::factory()->create();
    $service = quarterlySalesService();

    $discovered = $service->$method(8);
    $explicit = $service->$method(10, [$empty->id, $empty->id]);

    expect(array_column($discovered['series'], 'entity_id'))->not->toContain($empty->id);
    expect($explicit['series'])->toHaveCount(1);
    expect(array_column($explicit['series'][0]['quarters'], 'quantity_sold'))->toBe(array_fill(0, 10, 0));
    expect(array_column($explicit['series'][0]['quarters'], 'item_revenue'))->toBe(array_fill(0, 10, '0.00'));
    expect($service->$method(8, [])['series'])->toBe([]);
})->with([
    ['completedProducts', Product::class],
    ['completedCategories', Category::class],
]);

test('empty database returns no discovered series', function () {
    expect(quarterlySalesService()->completedProducts(8)['series'])->toBe([]);
    expect(quarterlySalesService()->completedCategories(8)['series'])->toBe([]);
});

test('sale dates own boundaries and zero buckets include leading internal and trailing gaps', function () {
    $product = Product::factory()->create();
    foreach (['2024-12-31', '2025-04-01', '2025-06-30', '2026-01-01'] as $date) {
        $sale = quarterlySale($product, $date);
        $sale->order->forceFill(['created_at' => '2026-10-15 12:00:00'])->save();
    }

    $result = quarterlySalesService()->products(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2026-01-01'));

    expect($result['series'][0]['quarters'])->toBe([
        ['year' => 2025, 'quarter' => 1, 'start' => '2025-01-01', 'end_exclusive' => '2025-04-01', 'quantity_sold' => 0, 'item_revenue' => '0.00'],
        ['year' => 2025, 'quarter' => 2, 'start' => '2025-04-01', 'end_exclusive' => '2025-07-01', 'quantity_sold' => 2, 'item_revenue' => '0.20'],
        ['year' => 2025, 'quarter' => 3, 'start' => '2025-07-01', 'end_exclusive' => '2025-10-01', 'quantity_sold' => 0, 'item_revenue' => '0.00'],
        ['year' => 2025, 'quarter' => 4, 'start' => '2025-10-01', 'end_exclusive' => '2026-01-01', 'quantity_sold' => 0, 'item_revenue' => '0.00'],
    ]);
});

test('completed history uses application timezone and excludes the incomplete quarter', function (string $asOf, string $timezone, string $start, string $end) {
    config(['app.timezone' => $timezone]);
    $reference = CarbonImmutable::parse($asOf, 'UTC');
    $this->travelTo($reference);
    $product = Product::factory()->create();
    quarterlySale($product, $start, 2);
    quarterlySale($product, CarbonImmutable::parse($end)->subDay()->toDateString(), 3);
    quarterlySale($product, $end, 100);

    $result = quarterlySalesService()->completedProducts(1, [$product->id]);
    $explicit = quarterlySalesService()->completedCategories(1, [$product->category_id], $reference);

    expect($result['start'])->toBe($start);
    expect($result['end_exclusive'])->toBe($end);
    expect($result['series'][0]['quarters'][0]['quantity_sold'])->toBe(5);
    expect($explicit['series'][0]['quarters'])->toBe($result['series'][0]['quarters']);
})->with([
    ['2026-09-30 23:59:59', 'UTC', '2026-04-01', '2026-07-01'],
    ['2026-10-01 00:00:00', 'UTC', '2026-07-01', '2026-10-01'],
    ['2027-01-01 00:00:00', 'UTC', '2026-10-01', '2027-01-01'],
    ['2024-04-01 00:00:00', 'UTC', '2024-01-01', '2024-04-01'],
    ['2026-09-30 16:00:00', 'Asia/Shanghai', '2026-07-01', '2026-10-01'],
]);

test('money remains exact across fractional prices repeated lines and large totals', function () {
    $product = Product::factory()->create(['price' => '999.00']);
    quarterlySale($product, '2026-07-01', 3, '0.10');
    quarterlySale($product, '2026-08-01', 7, '0.29');
    quarterlySale($product, '2026-08-02', 1, '9999999999.99');
    quarterlySale($product, '2026-08-03', 1, '9999999999.99');
    $sale = quarterlySale($product, '2026-09-01', 1, '0.10');
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => 2, 'price_at_time' => '0.10']);

    $products = quarterlySalesService()->completedProducts(1);
    $categories = quarterlySalesService()->completedCategories(1);

    expect($products['series'][0]['quarters'][0]['quantity_sold'])->toBe(15);
    expect($products['series'][0]['quarters'][0]['item_revenue'])->toBe('20000000002.61');
    expect($categories['series'][0]['quarters'])->toBe($products['series'][0]['quarters']);
});

test('customer identity and current inventory do not affect historical demand', function () {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $before = quarterlySalesService()->completedProducts(8);
    $customer = User::factory()->customer()->create();
    Order::query()->update(['user_id' => $customer->id, 'recipient_name' => 'Changed customer']);
    $customer->update(['name' => 'Renamed', 'email' => 'renamed@example.test']);
    Inventory::query()->update(['quantity' => 0, 'last_updated' => '2000-01-01 00:00:00']);
    Inventory::query()->firstOrFail()->delete();

    expect(quarterlySalesService()->completedProducts(8))->toBe($before);
});

test('separate customers contribute to one series and orders without sales do not contribute', function () {
    $product = Product::factory()->create();
    quarterlySale($product, '2026-07-01', 2);
    quarterlySale($product, '2026-07-02', 3);
    OrderItem::factory()->for($product)->create(['quantity' => 100]);

    $result = quarterlySalesService()->completedProducts(1);

    expect($result['series'])->toHaveCount(1);
    expect($result['series'][0]['quarters'][0]['quantity_sold'])->toBe(5);
});

test('category history follows current product category membership', function () {
    $product = Product::factory()->create();
    $oldCategory = $product->category_id;
    quarterlySale($product, '2026-07-01');
    $category = Category::factory()->create(['is_active' => false]);
    $product->update(['category_id' => $category->id, 'is_active' => false]);

    $result = quarterlySalesService()->completedCategories(1, [$category->id, $oldCategory]);

    $series = collect($result['series'])->keyBy('entity_id');
    expect($series[$oldCategory]['quarters'][0]['quantity_sold'])->toBe(0);
    expect($series[$category->id]['quarters'][0]['quantity_sold'])->toBe(1);
});

test('queries stay bounded for multiple entities and quarters and output is deterministic', function () {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $ids = Product::query()->orderByDesc('id')->pluck('id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $explicit = quarterlySalesService()->completedProducts(8, $ids);
    $explicitQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $discovered = quarterlySalesService()->completedProducts(8);
    $discoveryQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($explicitQueries)->toHaveCount(2);
    expect($discoveryQueries)->toHaveCount(1);
    expect(strtolower($discoveryQueries[0]['query']))->toContain('sum(', 'group by');
    sort($ids);
    expect(array_column($explicit['series'], 'entity_id'))->toBe($ids);
    expect($discovered['series'])->toHaveCount(6);
    expect(quarterlySalesService()->completedProducts(8))->toBe($discovered);
});

test('invalid windows are rejected', function (string $start, string $end) {
    expect(fn () => quarterlySalesService()->products(CarbonImmutable::parse($start), CarbonImmutable::parse($end)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['2026-01-02', '2026-04-01'],
    ['2026-01-01', '2026-04-02'],
    ['2026-01-01 00:00:01', '2026-04-01'],
    ['2026-04-01', '2026-04-01'],
    ['2026-07-01', '2026-04-01'],
]);

test('invalid quarter counts are rejected', function (int $count) {
    expect(fn () => quarterlySalesService()->completedProducts($count))->toThrow(InvalidArgumentException::class);
    expect(fn () => quarterlySalesService()->completedCategories($count))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

test('invalid or nonexistent entity IDs are rejected', function (array $ids) {
    expect(fn () => quarterlySalesService()->completedProducts(1, $ids))->toThrow(InvalidArgumentException::class);
    expect(fn () => quarterlySalesService()->completedCategories(1, $ids))->toThrow(InvalidArgumentException::class);
})->with([[[-1]], [[0]], [['1']], [[1.5]], [[999999]]]);
