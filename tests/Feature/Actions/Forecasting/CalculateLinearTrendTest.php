<?php

use App\Actions\Forecasting\CalculateLinearTrend;
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

test('fits known historical products directly from shared aggregation without additional queries', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $products = Product::query()->pluck('id', 'product_code');
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(8, $products->values()->all());
    $original = $history;
    $action = app(CalculateLinearTrend::class);
    $expected = [
        'STABLE' => ['0.000000', '10.000000', '10.000000', '10.00'],
        'INCREASING' => ['5.000000', '0.000000', '45.000000', '45.00'],
        'DECLINING' => ['-5.000000', '45.000000', '0.000000', '0.00'],
        'REPEATING' => ['1.238095', '5.428571', '16.571429', '16.57'],
        'SPARSE' => ['0.857143', '-1.607143', '6.107143', '6.11'],
        'PRICE' => ['0.000000', '4.000000', '4.000000', '4.00'],
        'NOHISTORY' => ['0.000000', '0.000000', '0.000000', '0.00'],
    ];
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        foreach ($expected as $code => [$slope, $intercept, $raw, $quantity]) {
            $result = $action->execute($history, $products['DEVHIST40'.$code]);

            expect($result['status'])->toBe('ok');
            expect($result['dimension'])->toBe('product');
            expect($result['slope'])->toBe($slope);
            expect($result['intercept'])->toBe($intercept);
            expect($result['raw_forecast_quantity'])->toBe($raw);
            expect($result['forecast_quantity'])->toBe($quantity);
            expect($result['was_clamped'])->toBeFalse();
            expect($result['source_period'])->toBe(['start' => '2024-10-01', 'end_exclusive' => '2026-10-01']);
            expect($result['target_quarter'])->toBe(['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']);
        }
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
    expect($history)->toBe($original);
});

test('fits category quantities with the same contract and no additional queries', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $history = app(QuarterlySalesAggregationService::class)->completedCategories(8);
    $categories = Category::query()->pluck('id', 'name');
    $action = app(CalculateLinearTrend::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $components = $action->execute($history, $categories['Historical Sales Components']);
        $peripherals = $action->execute($history, $categories['Historical Sales Peripherals']);

        expect($components['dimension'])->toBe('category');
        expect($components['status'])->toBe('ok');
        expect($components['slope'])->toBe('0.000000');
        expect($components['intercept'])->toBe('55.000000');
        expect($components['raw_forecast_quantity'])->toBe('55.000000');
        expect($components['forecast_quantity'])->toBe('55.00');
        expect($peripherals['dimension'])->toBe('category');
        expect($peripherals['status'])->toBe('ok');
        expect($peripherals['slope'])->toBe('2.095238');
        expect($peripherals['intercept'])->toBe('7.821429');
        expect($peripherals['raw_forecast_quantity'])->toBe('26.678571');
        expect($peripherals['forecast_quantity'])->toBe('26.68');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

test('distinguishes undiscovered history from explicitly aggregated zero demand in both dimensions', function (string $method, string $model) {
    $entity = $model::factory()->create();
    $aggregation = app(QuarterlySalesAggregationService::class);
    $action = app(CalculateLinearTrend::class);

    $missing = $action->execute($aggregation->$method(4), $entity->id);
    $zero = $action->execute($aggregation->$method(4, [$entity->id]), $entity->id);
    $insufficient = $action->execute($aggregation->$method(3, [$entity->id]), $entity->id);

    expect($missing['status'])->toBe('missing_history');
    expect($missing['forecast_quantity'])->toBeNull();
    expect($zero['status'])->toBe('ok');
    expect($zero['forecast_quantity'])->toBe('0.00');
    expect($insufficient['status'])->toBe('insufficient_history');
    expect($insufficient['forecast_quantity'])->toBeNull();
})->with([
    'product' => ['completedProducts', Product::class],
    'category' => ['completedCategories', Category::class],
]);

test('uses supplied history even after the application clock and timezone change', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40SPARSE')->sole();
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(8, [$product->id]);
    $action = app(CalculateLinearTrend::class);
    $before = $action->execute($history, $product->id);
    $this->travelTo(CarbonImmutable::parse('2030-01-15 12:00:00', 'Asia/Shanghai'));
    config(['app.timezone' => 'Asia/Shanghai']);

    expect($action->execute($history, $product->id))->toBe($before);
});
