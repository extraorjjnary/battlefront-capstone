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

test('monthly coverage cannot enter quarterly preparation or persist a legacy forecast', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40STABLE')->sole();
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
        ->assertInertia(fn (Assert $page) => $page->where('readiness.status', 'history_unavailable'));
    $this->post(route('administration.forecasting.store'), ['product_id' => $product->id])
        ->assertInertiaFlash('forecast_result.status', 'history_unavailable');
    expect(Forecast::count())->toBe(0);
});
