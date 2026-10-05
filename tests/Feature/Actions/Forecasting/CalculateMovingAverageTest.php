<?php

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Models\Category;
use App\Models\Product;
use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

test('forecasts known historical products directly from shared aggregation without additional queries', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $products = Product::query()->pluck('id', 'product_code');
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(8, $products->values()->all());
    $original = $history;
    $action = new CalculateMovingAverage;
    $expected = ['STABLE' => '10.00', 'INCREASING' => '32.50', 'DECLINING' => '12.50', 'REPEATING' => '11.00', 'SPARSE' => '3.75', 'PRICE' => '4.00', 'NOHISTORY' => '0.00'];
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        foreach ($expected as $code => $quantity) {
            $result = $action->execute($history, $products['DEVHIST40'.$code]);

            expect($result['status'])->toBe('ok');
            expect($result['forecast_quantity'])->toBe($quantity);
            expect($result['source_period'])->toBe(['start' => '2025-10-01', 'end_exclusive' => '2026-10-01']);
            expect($result['target_quarter'])->toBe(['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']);
        }
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
    expect($history)->toBe($original);
});

test('accepts category history with the same calculation contract', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $history = app(QuarterlySalesAggregationService::class)->completedCategories(8);
    $categories = Category::query()->pluck('id', 'name');
    $action = new CalculateMovingAverage;

    $components = $action->execute($history, $categories['Historical Sales Components']);
    $peripherals = $action->execute($history, $categories['Historical Sales Peripherals']);

    expect($components['dimension'])->toBe('category');
    expect($components['forecast_quantity'])->toBe('55.00');
    expect($peripherals['forecast_quantity'])->toBe('18.75');
});

test('distinguishes undiscovered history from explicitly aggregated zero demand', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40NOHISTORY')->sole();
    $aggregation = app(QuarterlySalesAggregationService::class);
    $action = new CalculateMovingAverage;

    $missing = $action->execute($aggregation->completedProducts(4), $product->id);
    $zero = $action->execute($aggregation->completedProducts(4, [$product->id]), $product->id);

    expect($missing['status'])->toBe('missing_history');
    expect($missing['forecast_quantity'])->toBeNull();
    expect($zero['status'])->toBe('ok');
    expect($zero['forecast_quantity'])->toBe('0.00');
});

test('uses supplied history even after the application clock and timezone change', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);
    $action = new CalculateMovingAverage;
    $before = $action->execute($history, $product->id);
    $this->travelTo(CarbonImmutable::parse('2030-01-15 12:00:00', 'Asia/Shanghai'));
    config(['app.timezone' => 'Asia/Shanghai']);

    expect($action->execute($history, $product->id))->toBe($before);
});
