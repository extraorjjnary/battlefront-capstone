<?php

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use App\Actions\Forecasting\EvaluateQuarterlyForecasts;
use App\Models\Product;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\MonthlyForecastFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

test('consumes EXT42 prepared EXT40 scenarios without another query or changing history', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $expected = ['STABLE' => '30.00', 'REPEATING' => '44.00', 'INCREASING' => '338.00', 'DECLINING' => '66.00', 'MIXEDZERO' => '38.00', 'PRICE' => '12.00', 'NOHISTORY' => '0.00'];
    $service = app(ProductForecastPreparationService::class);
    $prepared = [];
    foreach ($expected as $code => $quantity) {
        $product = Product::where('product_code', 'DEVHIST40'.$code)->sole();
        $prepared[$code] = $service->prepareMonthly($product);
    }
    $original = $prepared;
    $this->travelTo(CarbonImmutable::parse('2030-01-01', 'UTC'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        foreach ($expected as $code => $quantity) {
            $result = app(CalculateAdditiveHoltWinters::class)->execute($prepared[$code]);
            expect($result['status'])->toBe('ok');
            expect($result['forecast_quantity'])->toBe($quantity);
            expect($result['source_period'])->toBe($prepared[$code]['source_period']);
            expect($result['target_quarter'])->toBe($prepared[$code]['target_quarter']);
            expect(array_column($result['monthly_forecasts'], 'start'))->toBe(['2026-10-01', '2026-11-01', '2026-12-01']);
            expect($result['source_kind'])->toBe('synthetic_development');
            expect($result['candidates_evaluated'])->toBe($code === 'NOHISTORY' ? 0 : 900);
        }
        expect($prepared)->toBe($original);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

test('preserves EXT42 coverage and sparse eligibility failures without fallback or queries', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $expected = ['SPARSE' => 'history_unsuitable', 'SHORT' => 'insufficient_history', 'UNKNOWN' => 'history_unavailable', 'STALE' => 'history_unavailable', 'GAPPED' => 'history_unavailable'];
    $prepared = [];
    foreach ($expected as $code => $status) {
        $prepared[$code] = app(ProductForecastPreparationService::class)->prepareMonthly(Product::where('product_code', 'DEVHIST40'.$code)->sole());
    }
    $this->expectsDatabaseQueryCount(0);
    foreach ($expected as $code => $status) {
        $result = app(CalculateAdditiveHoltWinters::class)->execute($prepared[$code]);
        expect($result['status'])->toBe($status);
        expect($result['message'])->toBe($prepared[$code]['message']);
        expect($result['forecast_quantity'])->toBeNull();
        expect($result['parameters'])->toBeNull();
        expect($result['candidates_evaluated'])->toBe(0);
        expect($result['monthly_forecasts'])->toBe([]);
    }
});

test('evaluates four real preparation origins against known synthetic held out quantities', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40DECLINING')->sole();
    $folds = [];
    foreach (['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01'] as $index => $origin) {
        $prepared = app(ProductForecastPreparationService::class)->prepareMonthly($product, CarbonImmutable::parse($origin, 'UTC'));
        $actuals = [48 - 6 * $index, 46 - 6 * $index, 44 - 6 * $index];
        $folds[] = ['preparation' => $prepared, 'actual_months' => MonthlyForecastFixtures::months($actuals, $origin)];
    }
    $this->expectsDatabaseQueryCount(0);

    $result = app(EvaluateQuarterlyForecasts::class)->execute($folds);

    expect($result['evaluated_count'])->toBe(4);
    expect(array_column($result['origins'], 'actual_quantity'))->toBe(['138.000000000000', '120.000000000000', '102.000000000000', '84.000000000000']);
    expect($result['mae_display'])->toBe(['additive_holt_winters' => '0.00', 'seasonal_naive' => '72.00', 'moving_average' => '45.00']);
    expect($result['source_kinds'])->toBe(['synthetic_development']);
});
