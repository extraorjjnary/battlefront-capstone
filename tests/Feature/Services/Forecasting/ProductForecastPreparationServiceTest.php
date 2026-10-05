<?php

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Forecasting\ProductForecastPreparationService;
use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function productForecastCoverage(array $overrides = []): array
{
    return array_replace([
        'start' => '2025-10-01',
        'end_exclusive' => '2026-10-01',
        'unavailable_quarters' => [],
        'timezone' => 'UTC',
        'source_kind' => 'operational_prepared',
        'sales_scope' => 'captured_system_transactions',
    ], $overrides);
}

function productForecastSale(Product $product, string $date, int $quantity): void
{
    $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => bcmul('0.10', (string) $quantity, 2)]);
    $sale->order->forceFill(['created_at' => '2026-10-15 12:00:00'])->save();
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => $quantity, 'price_at_time' => '0.10']);
}

test('prepares exactly four trusted fixture quarters and integrates with the pure moving average', function (string $code, array $quantities, array $revenues, string $average) {
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40'.$code)->sole();

    $result = app(ProductForecastPreparationService::class)->prepare($product);

    expect(array_diff_key($result, ['history' => true]))->toBe([
        'status' => 'ready',
        'product_id' => $product->id,
        'product_code' => $product->product_code,
        'timezone' => 'UTC',
        'source_period' => ['start' => '2025-10-01', 'end_exclusive' => '2026-10-01'],
        'target_quarter' => ['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
        'covered_quarters' => 4,
        'coverage' => productForecastCoverage([
            'start' => '2024-10-01',
            'source_kind' => 'synthetic_development',
            'sales_scope' => 'development_fixture_transactions',
        ]),
    ]);
    expect($product->is_active)->toBeFalse();
    $history = $result['history'];
    expect($history['dimension'])->toBe('product');
    expect($history['timezone'])->toBe('UTC');
    expect($history['start'])->toBe('2025-10-01');
    expect($history['end_exclusive'])->toBe('2026-10-01');
    expect($history['series'])->toHaveCount(1);
    expect($history['series'][0]['entity_id'])->toBe($product->id);
    $quarters = $history['series'][0]['quarters'];
    expect($quarters)->toHaveCount(4);
    expect(array_column($quarters, 'start'))->toBe(['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01']);
    expect(array_column($quarters, 'quantity_sold'))->toBe($quantities);
    expect(array_column($quarters, 'item_revenue'))->toBe($revenues);
    $original = $history;
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $forecast = (new CalculateMovingAverage)->execute($history, $product->id);

        expect($forecast['status'])->toBe('ok');
        expect($forecast['available_quarters'])->toBe(4);
        expect($forecast['forecast_quantity'])->toBe($average);
        expect($forecast['source_period'])->toBe($result['source_period']);
        expect($forecast['target_quarter'])->toBe($result['target_quarter']);
        expect($history)->toBe($original);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with([
    'stable' => ['STABLE', [10, 10, 10, 10], array_fill(0, 4, '10000.00'), '10.00'],
    'increasing' => ['INCREASING', [25, 30, 35, 40], ['50000.00', '60000.00', '70000.00', '80000.00'], '32.50'],
    'declining' => ['DECLINING', [20, 15, 10, 5], ['30000.00', '22500.00', '15000.00', '7500.00'], '12.50'],
    'repeating' => ['REPEATING', [4, 8, 12, 20], ['2000.00', '4000.00', '6000.00', '10000.00'], '11.00'],
    'mixed zero' => ['SPARSE', [0, 6, 0, 9], ['0.00', '15000.00', '0.00', '22500.00'], '3.75'],
    'purchase-time prices' => ['PRICE', [4, 4, 4, 4], ['600.00', '600.00', '700.00', '700.00'], '4.00'],
    'all zero' => ['NOHISTORY', [0, 0, 0, 0], array_fill(0, 4, '0.00'), '0.00'],
]);

test('short complete declarations return insufficient history without queries or buckets', function (string $start, string $end, int $count) {
    Storage::fake('local');
    $product = Product::factory()->create(['product_code' => '00123']);
    $coverage = productForecastCoverage(['start' => $start, 'end_exclusive' => $end]);
    config(['forecasting.operational_coverage' => [$product->product_code => $coverage]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(ProductForecastPreparationService::class)->prepare($product);

        expect($result)->toBe([
            'status' => 'insufficient_history',
            'product_id' => $product->id,
            'product_code' => '00123',
            'timezone' => 'UTC',
            'source_period' => ['start' => '2025-10-01', 'end_exclusive' => '2026-10-01'],
            'target_quarter' => ['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
            'covered_quarters' => $count,
            'coverage' => $coverage,
            'history' => null,
        ]);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with([
    'zero covered' => ['2026-10-01', '2026-10-01', 0],
    'one covered' => ['2026-07-01', '2026-10-01', 1],
    'two covered' => ['2026-04-01', '2026-10-01', 2],
    'three covered' => ['2026-01-01', '2026-10-01', 3],
    'future end does not count target' => ['2026-07-01', '2027-04-01', 1],
    'future only' => ['2027-01-01', '2027-04-01', 0],
]);

test('unavailable coverage never queries or fills observations', function (array $overrides, bool $validDeclaration) {
    Storage::fake('local');
    $product = Product::factory()->create();
    $coverage = productForecastCoverage($overrides);
    config(['forecasting.operational_coverage' => [$product->product_code => $coverage]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(ProductForecastPreparationService::class)->prepare($product);

        expect($result['status'])->toBe('history_unavailable');
        expect($result['history'])->toBeNull();
        expect($result['covered_quarters'])->toBeNull();
        expect($result['coverage'])->toBe($validDeclaration ? $coverage : null);
        expect($result['source_period'])->toBe(['start' => '2025-10-01', 'end_exclusive' => '2026-10-01']);
        expect($result['target_quarter']['start'])->toBe('2026-10-01');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with([
    'stale end' => [['end_exclusive' => '2026-07-01'], true],
    'stale empty declaration' => [['start' => '2026-07-01', 'end_exclusive' => '2026-07-01'], true],
    'gap at first required quarter' => [['unavailable_quarters' => ['2025-10-01']], true],
    'internal gap' => [['unavailable_quarters' => ['2026-04-01']], true],
    'gap at last completed quarter' => [['unavailable_quarters' => ['2026-07-01']], true],
    'gap takes precedence over short history' => [['start' => '2026-04-01', 'unavailable_quarters' => ['2026-07-01']], true],
    'malformed start' => [['start' => null], false],
    'unaligned end' => [['end_exclusive' => '2026-10-02'], false],
    'reversed dates' => [['start' => '2027-01-01'], false],
    'wrong timezone' => [['timezone' => 'Asia/Shanghai'], false],
    'untrusted source' => [['source_kind' => 'unknown'], false],
    'uncertain scope' => [['sales_scope' => 'unknown'], false],
    'malformed gap list' => [['unavailable_quarters' => null], false],
]);

test('recorded sales product age and reporting buckets cannot establish missing coverage', function () {
    Storage::fake('local');
    $product = Product::factory()->create(['created_at' => '2020-01-01']);
    foreach (['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01'] as $date) {
        productForecastSale($product, $date, 10);
    }
    $generic = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);
    expect(array_column($generic['series'][0]['quarters'], 'quantity_sold'))->toBe([10, 10, 10, 10]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(ProductForecastPreparationService::class)->prepare($product);

        expect($result['status'])->toBe('history_unavailable');
        expect($result['coverage'])->toBeNull();
        expect($result['covered_quarters'])->toBeNull();
        expect($result['history'])->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

test('generic zeros remain unknown until an operational declaration establishes coverage', function (string $scope) {
    Storage::fake('local');
    $product = Product::factory()->create();
    $generic = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);
    expect(array_column($generic['series'][0]['quarters'], 'quantity_sold'))->toBe([0, 0, 0, 0]);
    $service = app(ProductForecastPreparationService::class);

    $unknown = $service->prepare($product);
    config(['forecasting.operational_coverage' => [$product->product_code => productForecastCoverage(['sales_scope' => $scope])]]);
    $ready = $service->prepare($product);

    expect($unknown['status'])->toBe('history_unavailable');
    expect($unknown['history'])->toBeNull();
    expect($ready['status'])->toBe('ready');
    expect($ready['coverage']['sales_scope'])->toBe($scope);
    expect(array_column($ready['history']['series'][0]['quarters'], 'quantity_sold'))->toBe([0, 0, 0, 0]);
    expect((new CalculateMovingAverage)->execute($ready['history'], $product->id)['forecast_quantity'])->toBe('0.00');
})->with(['all_sagay_sales', 'captured_system_transactions']);

test('malformed development manifests report unavailable history', function (string $contents) {
    $disk = Storage::fake('local');
    $product = Product::factory()->create();
    $disk->put(config('forecasting.development_manifest'), $contents);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(ProductForecastPreparationService::class)->prepare($product);

        expect($result['status'])->toBe('history_unavailable');
        expect($result['history'])->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with(['invalid JSON' => '{', 'wrong version' => '{"version":2,"products":{}}']);

test('gaps outside the required window do not invalidate four completed quarters', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    $coverage = productForecastCoverage([
        'start' => '2024-10-01',
        'end_exclusive' => '2027-01-01',
        'unavailable_quarters' => ['2024-10-01', '2026-10-01'],
    ]);
    config(['forecasting.operational_coverage' => [$product->product_code => $coverage]]);

    $result = app(ProductForecastPreparationService::class)->prepare($product);

    expect($result['status'])->toBe('ready');
    expect($result['covered_quarters'])->toBe(4);
    expect($result['coverage'])->toBe($coverage);
    expect($result['history']['series'][0]['quarters'])->toHaveCount(4);
});

test('preparation excludes older and target sales uses sale dates and orders deterministically', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => productForecastCoverage()]]);
    foreach ([
        ['2026-10-01', 200], ['2026-09-30', 4], ['2025-12-31', 2],
        ['2025-09-30', 100], ['2026-11-01', 500], ['2025-10-01', 1],
    ] as [$date, $quantity]) {
        productForecastSale($product, $date, $quantity);
    }
    productForecastSale($otherProduct, '2026-07-01', 1000);
    $service = app(ProductForecastPreparationService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = $service->prepare($product);

        expect(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
    }

    $quarters = $result['history']['series'][0]['quarters'];
    expect(array_column($quarters, 'start'))->toBe(['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01']);
    expect(array_column($quarters, 'quantity_sold'))->toBe([3, 0, 0, 4]);
    expect(array_column($quarters, 'item_revenue'))->toBe(['0.30', '0.00', '0.00', '0.40']);
    expect($service->prepare($product))->toBe($result);
    expect((new CalculateMovingAverage)->execute($result['history'], $product->id)['forecast_quantity'])->toBe('1.75');
});

test('reference time is converted to the application timezone for the source and target', function (string $reference, string $timezone, string $start, string $target, int $year, int $quarter, string $targetEnd) {
    Storage::fake('local');
    config(['app.timezone' => $timezone]);
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => productForecastCoverage([
        'start' => $start, 'end_exclusive' => $target, 'timezone' => $timezone,
    ])]]);
    $asOf = CarbonImmutable::parse($reference, 'UTC');
    $this->travelTo($asOf);

    $service = app(ProductForecastPreparationService::class);
    $result = $service->prepare($product);
    $explicit = $service->prepare($product, $asOf);

    expect($result)->toBe($explicit);
    expect($result['status'])->toBe('ready');
    expect($result['timezone'])->toBe($timezone);
    expect($result['source_period'])->toBe(['start' => $start, 'end_exclusive' => $target]);
    expect($result['target_quarter'])->toBe(['year' => $year, 'quarter' => $quarter, 'start' => $target, 'end_exclusive' => $targetEnd]);
    expect($result['history']['start'])->toBe($start);
    expect($result['history']['end_exclusive'])->toBe($target);
    expect($result['history']['series'][0]['quarters'])->toHaveCount(4);
})->with([
    'before Q4' => ['2026-09-30 23:59:59', 'UTC', '2025-07-01', '2026-07-01', 2026, 3, '2026-10-01'],
    'Q4 starts' => ['2026-10-01 00:00:00', 'UTC', '2025-10-01', '2026-10-01', 2026, 4, '2027-01-01'],
    'year rollover' => ['2027-01-01 00:00:00', 'UTC', '2026-01-01', '2027-01-01', 2027, 1, '2027-04-01'],
    'local quarter ahead of UTC' => ['2026-09-30 16:00:00', 'Asia/Shanghai', '2025-10-01', '2026-10-01', 2026, 4, '2027-01-01'],
    'leap quarter completed' => ['2024-04-01 00:00:00', 'UTC', '2023-04-01', '2024-04-01', 2024, 2, '2024-07-01'],
]);

test('quarter rollover makes unchanged declarations stale until preparation republishes coverage', function () {
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $service = app(ProductForecastPreparationService::class);
    $manifest = $disk->get(config('forecasting.development_manifest'));
    expect($service->prepare($product)['status'])->toBe('ready');
    $this->travelTo(CarbonImmutable::parse('2027-01-01', 'UTC'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $stale = $service->prepare($product);

        expect($stale['status'])->toBe('history_unavailable');
        expect($stale['history'])->toBeNull();
        expect($stale['source_period'])->toBe(['start' => '2026-01-01', 'end_exclusive' => '2027-01-01']);
        expect($stale['coverage']['end_exclusive'])->toBe('2026-10-01');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
    expect($disk->get(config('forecasting.development_manifest')))->toBe($manifest);

    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $ready = $service->prepare($product);
    expect($ready['status'])->toBe('ready');
    expect($ready['coverage']['end_exclusive'])->toBe('2027-01-01');
    expect($ready['target_quarter']['start'])->toBe('2027-01-01');
});

test('synthetic metadata cannot establish production readiness', function () {
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $this->app->instance('env', 'production');

    $result = app(ProductForecastPreparationService::class)->prepare($product);

    expect($result['status'])->toBe('history_unavailable');
    expect($result['coverage'])->toBeNull();
    expect($result['history'])->toBeNull();
});

test('current inventory changes and missing stock do not alter prepared history', function () {
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $service = app(ProductForecastPreparationService::class);
    $before = $service->prepare($product);
    Inventory::where('product_id', $product->id)->update(['quantity' => 0, 'reorder_level' => 999, 'last_updated' => '2000-01-01 00:00:00']);

    expect($service->prepare($product))->toBe($before);
    Inventory::where('product_id', $product->id)->delete();
    expect($service->prepare($product))->toBe($before);
});

test('an unsaved product cannot be used for forecast preparation', function () {
    $product = Product::factory()->make();

    expect(fn () => app(ProductForecastPreparationService::class)->prepare($product))
        ->toThrow(InvalidArgumentException::class, 'persisted product');
});
