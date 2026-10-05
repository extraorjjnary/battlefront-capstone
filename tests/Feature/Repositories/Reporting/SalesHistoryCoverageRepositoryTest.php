<?php

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Models\Product;
use App\Models\Sale;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
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

function preparedSalesCoverage(array $overrides = []): array
{
    return array_replace([
        'start' => '2024-10-01', 'end_exclusive' => '2026-10-01',
        'unavailable_quarters' => [], 'timezone' => 'UTC',
        'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
    ], $overrides);
}

test('operational coverage stays unavailable despite existing records and generated zero buckets', function () {
    Storage::fake('local');
    $product = Product::factory()->create(['product_code' => '00123', 'created_at' => '2020-01-01']);
    Sale::factory()->create(['sale_date' => '2020-01-01']);
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);

    expect(config('forecasting.operational_coverage'))->toBe([]);
    expect($history['series'][0]['quarters'])->toHaveCount(4);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('00123'))->toBeNull();
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('operational declarations preserve exact product codes and explicit sales scope without database queries', function (string $scope) {
    Storage::fake('local');
    $entry = preparedSalesCoverage(['sales_scope' => $scope]);
    config(['forecasting.operational_coverage' => ['00123' => $entry]]);
    $this->app->instance('env', 'production');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $repository = app(SalesHistoryCoverageRepository::class);
        expect($repository->forProductCode('00123'))->toBe($entry);
        expect($repository->forProductCode('123'))->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with(['all_sagay_sales', 'captured_system_transactions']);

test('malformed declarations return no trusted coverage', function (array $overrides) {
    Storage::fake('local');
    config(['forecasting.operational_coverage' => ['00123' => preparedSalesCoverage($overrides)]]);

    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('00123'))->toBeNull();
})->with([
    'missing start' => [['start' => null]],
    'missing end' => [['end_exclusive' => null]],
    'non date' => [['start' => 123]],
    'zero year' => [['start' => '0000-01-01']],
    'normalized invalid date' => [['start' => '2025-02-30']],
    'unaligned date' => [['start' => '2025-10-02']],
    'time included' => [['start' => '2025-10-01 00:00:00']],
    'reversed interval' => [['start' => '2027-01-01']],
    'timezone mismatch' => [['timezone' => 'Asia/Shanghai']],
    'unknown source' => [['source_kind' => 'unverified']],
    'missing source' => [['source_kind' => null]],
    'missing scope' => [['sales_scope' => null]],
    'synthetic operational declaration' => [['source_kind' => 'synthetic_development']],
    'unknown scope' => [['sales_scope' => 'unknown']],
    'null gaps' => [['unavailable_quarters' => null]],
    'non list gaps' => [['unavailable_quarters' => ['gap' => '2026-01-01']]],
    'unaligned gap' => [['unavailable_quarters' => ['2026-02-01']]],
    'gap at exclusive end' => [['unavailable_quarters' => ['2026-10-01']]],
    'gap before interval' => [['unavailable_quarters' => ['2024-07-01']]],
    'duplicate gaps' => [['unavailable_quarters' => ['2026-01-01', '2026-01-01']]],
]);

test('short complete intervals preserve zero to three quarters for later insufficient history evaluation', function (string $start, int $completedQuarters) {
    Storage::fake('local');
    $entry = preparedSalesCoverage(['start' => $start]);
    unset($entry['unavailable_quarters']);
    config(['forecasting.operational_coverage' => ['00123' => $entry]]);

    $coverage = app(SalesHistoryCoverageRepository::class)->forProductCode('00123');

    expect($coverage['start'])->toBe($start);
    expect($coverage['end_exclusive'])->toBe('2026-10-01');
    expect($coverage['unavailable_quarters'])->toBe([]);
    expect((int) CarbonImmutable::parse($start)->diffInQuarters(CarbonImmutable::parse($coverage['end_exclusive'])))->toBe($completedQuarters);
})->with([['2026-10-01', 0], ['2026-07-01', 1], ['2026-04-01', 2], ['2026-01-01', 3]]);

test('stale ends and declared gaps remain explicit without clock based repairs', function () {
    Storage::fake('local');
    $stale = preparedSalesCoverage(['end_exclusive' => '2026-07-01']);
    config(['forecasting.operational_coverage' => [
        'STALE' => $stale,
        'GAPPED' => preparedSalesCoverage(['unavailable_quarters' => ['2026-01-01', '2024-10-01']]),
    ]]);
    $repository = app(SalesHistoryCoverageRepository::class);

    expect($repository->forProductCode('STALE'))->toBe($stale);
    expect($repository->forProductCode('GAPPED')['unavailable_quarters'])->toBe(['2024-10-01', '2026-01-01']);
    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    expect($repository->forProductCode('STALE'))->toBe($stale);
});

test('seeded coverage matches prepared history and known four quarter observations including zeros', function (string $code, array $quantities, string $average) {
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40'.$code)->sole();
    $repository = new SalesHistoryCoverageRepository;

    expect($repository->forProductCode($product->product_code))->toBe(preparedSalesCoverage([
        'source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions',
    ]));
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);
    expect($history['start'])->toBe('2025-10-01');
    expect($history['end_exclusive'])->toBe('2026-10-01');
    expect(array_column($history['series'][0]['quarters'], 'quantity_sold'))->toBe($quantities);
    expect((new CalculateMovingAverage)->execute($history, $product->id)['forecast_quantity'])->toBe($average);
    Storage::disk('local')->assertExists(config('forecasting.development_manifest'));
})->with([
    ['STABLE', [10, 10, 10, 10], '10.00'],
    ['INCREASING', [25, 30, 35, 40], '32.50'],
    ['DECLINING', [20, 15, 10, 5], '12.50'],
    ['REPEATING', [4, 8, 12, 20], '11.00'],
    ['SPARSE', [0, 6, 0, 9], '3.75'],
    ['PRICE', [4, 4, 4, 4], '4.00'],
    ['NOHISTORY', [0, 0, 0, 0], '0.00'],
]);

test('only explicit reseeding advances the development coverage at quarter rollover', function () {
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $path = config('forecasting.development_manifest');
    $before = $disk->get($path);
    $repository = app(SalesHistoryCoverageRepository::class);
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    expect($disk->get($path))->toBe($before);

    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    expect($repository->forProductCode('DEVHIST40STABLE')['end_exclusive'])->toBe('2026-10-01');
    expect($disk->get($path))->toBe($before);
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $entry = $repository->forProductCode('DEVHIST40STABLE');
    expect($entry['start'])->toBe('2025-01-01');
    expect($entry['end_exclusive'])->toBe('2027-01-01');
    $this->assertDatabaseCount('sales', 16);
    expect(config('forecasting.operational_coverage'))->toBe([]);
});

test('failed fixture preparation invalidates old coverage and rolls back record changes', function () {
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    Product::where('product_code', 'DEVHIST40STABLE')->sole()->forceFill(['is_catalog_imported' => true])->save();
    $before = DB::table('products')->get()->toArray();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'conflicts');

    expect(DB::table('products')->get()->toArray())->toEqual($before);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('DEVHIST40STABLE'))->toBeNull();
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('malformed development manifests cannot establish coverage', function (string $contents) {
    $disk = Storage::fake('local');
    $disk->put(config('forecasting.development_manifest'), $contents);

    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('DEVHIST40STABLE'))->toBeNull();
})->with(['{', '{"version":2,"products":{}}', '{"version":1,"products":null}']);

test('synthetic coverage cannot be read published or cleared in unsafe environments', function (string $environment) {
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $before = $disk->get(config('forecasting.development_manifest'));
    $this->app->instance('env', $environment);
    $repository = app(SalesHistoryCoverageRepository::class);

    expect($repository->forProductCode('DEVHIST40STABLE'))->toBeNull();
    expect(fn () => $repository->writeDevelopment([]))->toThrow(RuntimeException::class);
    expect(fn () => $repository->clearDevelopment())->toThrow(RuntimeException::class);
    expect($disk->get(config('forecasting.development_manifest')))->toBe($before);
})->with(['production', 'staging']);

test('conflicting source declarations cannot establish coverage or overwrite a manifest', function () {
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $repository = app(SalesHistoryCoverageRepository::class);
    $entry = $repository->forProductCode('DEVHIST40STABLE');
    $before = $disk->get(config('forecasting.development_manifest'));
    config(['forecasting.operational_coverage' => ['DEVHIST40STABLE' => preparedSalesCoverage()]]);

    expect($repository->forProductCode('DEVHIST40STABLE'))->toBeNull();
    expect(fn () => $repository->writeDevelopment(['DEVHIST40STABLE' => $entry]))->toThrow(InvalidArgumentException::class);
    expect($disk->get(config('forecasting.development_manifest')))->toBe($before);
});

test('invalid synthetic publication leaves the prior declaration intact', function () {
    $disk = Storage::fake('local');
    $repository = app(SalesHistoryCoverageRepository::class);
    $path = config('forecasting.development_manifest');
    $disk->put($path, 'prior declaration');

    expect(fn () => $repository->writeDevelopment(['INVALID-CODE' => preparedSalesCoverage()]))->toThrow(InvalidArgumentException::class);
    expect($disk->get($path))->toBe('prior declaration');
});

test('failed publication leaves prepared records without a trusted declaration', function () {
    $disk = Storage::fake('local');
    $this->partialMock(SalesHistoryCoverageRepository::class, function ($mock) {
        $mock->shouldReceive('writeDevelopment')->once()->andThrow(new RuntimeException('Publication failed.'));
    });

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'Publication failed');

    $this->assertDatabaseCount('sales', 16);
    $disk->assertMissing(config('forecasting.development_manifest'));
});
