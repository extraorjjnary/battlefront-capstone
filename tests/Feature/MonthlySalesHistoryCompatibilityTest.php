<?php

use App\Models\Forecast;
use App\Models\Product;
use App\Models\User;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

test('monthly generation preserves quarterly preparation isolation and legacy forecast records', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $legacy = Forecast::factory()->for($product)->create(['method' => 'moving_average', 'predicted_demand' => '12.00']);
    $snapshot = $legacy->fresh()->toArray();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(ProductForecastPreparationService::class)->prepare($product);
        expect($result['coverage']['granularity'])->toBe('month');
        expect($result['status'])->toBe('history_unavailable');
        expect($result['covered_quarters'])->toBeNull();
        expect($result['history'])->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('readiness.covered_months', 36));
    $this->post(route('administration.forecasting.store'), ['product_id' => $product->id])
        ->assertInertiaFlash('forecast_result.status', 'ready')
        ->assertInertiaFlash('forecast_result.method', 'additive_holt_winters')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '30.00');
    $this->assertDatabaseHas('forecasts', [
        'product_id' => $product->id, 'method' => 'additive_holt_winters',
        'forecast_quarter' => '2026-Q4', 'predicted_demand' => '30.00',
    ]);
    expect($legacy->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 2);
});
