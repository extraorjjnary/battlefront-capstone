<?php

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\LegacyQuarterlySalesFixtures;

beforeEach(function () {
    LegacyQuarterlySalesFixtures::bindCoverage();
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function forecastingSale(Product $product, string $date, int $quantity): void
{
    $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => '1.00']);
    OrderItem::factory()->for($sale->order)->for($product)->create([
        'quantity' => $quantity, 'price_at_time' => '0.00',
    ]);
}

/** @return array{product_id: int} */
function forecastingInput(Product $product): array
{
    return ['product_id' => $product->id];
}

/** @param array<string, mixed> $overrides */
function forecastingCoverage(Product $product, array $overrides = []): void
{
    config(['forecasting.operational_coverage' => [
        ...config('forecasting.operational_coverage', []),
        $product->product_code => [
            'start' => '2025-10-01',
            'end_exclusive' => '2026-10-01',
            'unavailable_quarters' => [],
            'timezone' => config('app.timezone'),
            'source_kind' => 'operational_prepared',
            'sales_scope' => 'captured_system_transactions',
            ...$overrides,
        ],
    ]]);
}

/** @return array<string, mixed> */
function forecastingPreparedLegacyResult(Product $product): array
{
    $prepared = app(ProductForecastPreparationService::class)->prepare($product);
    expect($prepared['status'])->toBe('ready');
    expect($prepared['history'])->not->toBeNull();

    return [
        'prepared' => $prepared,
        'result' => app(CalculateMovingAverage::class)->execute($prepared['history'], $product->id),
    ];
}

test('guests must log in and customers cannot read or generate forecasts', function (string $verb) {
    $this->$verb(route('administration.forecasting.'.($verb === 'get' ? 'index' : 'store')))
        ->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->customer()->create())
        ->$verb(route('administration.forecasting.'.($verb === 'get' ? 'index' : 'store')))
        ->assertForbidden();

    $this->assertDatabaseEmpty('forecasts');
})->with(['get', 'post']);

test('administrators see the dedicated forecasting page and empty states', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Forecasting')
            ->has('products.data', 0)
            ->has('forecasts.data', 0)
            ->where('selected_product', null)
            ->where('readiness', null)
            ->where('timezone', 'UTC')
            ->missing('methods')
            ->missing('history_end')
            ->missing('filters.method'));
});

test('rejects legacy moving average persistence after calculating the latest four completed quarters', function () {
    $product = Product::factory()->for(Category::factory()->state(['is_active' => false]))
        ->create(['is_active' => false]);
    forecastingCoverage($product, ['start' => '2025-04-01']);
    foreach (['2025-04-01' => 5, '2025-07-01' => 10, '2025-10-01' => 15, '2026-01-01' => 20, '2026-04-01' => 25, '2026-07-01' => 30] as $date => $quantity) {
        forecastingSale($product, $date, $quantity);
    }
    forecastingSale($product, '2026-10-01', 9999);
    $this->partialMock(PersistForecast::class)->shouldReceive('execute')->once()->passthru();
    $calculated = forecastingPreparedLegacyResult($product);

    expect($calculated['result'])->toMatchArray([
        'status' => 'ok', 'method' => 'moving_average', 'dimension' => 'product',
        'entity_id' => $product->id, 'forecast_quantity' => '22.50',
        'source_period' => ['start' => '2025-10-01', 'end_exclusive' => '2026-10-01'],
        'target_quarter' => ['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
    ]);
    expect(array_column($calculated['prepared']['history']['series'][0]['quarters'], 'quantity_sold'))
        ->toBe([15, 20, 25, 30]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product));

    $response->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');
    $this->assertDatabaseEmpty('forecasts');
    $this->get($response->headers->get('Location'))
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('forecast_result')
            ->has('forecasts.data', 0)
            ->where('readiness.status', 'ready')
            ->where('readiness.covered_quarters', 4)
            ->where('readiness.source_label', 'Q4 2025 – Q3 2026')
            ->where('selected_product.is_active', false));
});

test('rejected legacy reruns preserve all saved results and their generation times', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $administrator = User::factory()->administrator()->create();
    $otherMethod = Forecast::factory()->for($product)->create(['method' => 'linear_trend']);
    $otherQuarter = Forecast::factory()->for($product)->create(['forecast_quarter' => '2026-Q3']);
    $original = Forecast::factory()->for($product)->create([
        'method' => 'moving_average', 'forecast_quarter' => '2026-Q4',
        'predicted_demand' => '14.00', 'generated_at' => '2026-10-14 12:00:00',
    ]);
    $saved = Forecast::orderBy('id')->get()->toArray();

    $this->actingAs($administrator)
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');
    expect(Forecast::orderBy('id')->get()->toArray())->toBe($saved);
    forecastingSale($product, '2026-09-30', 20);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));

    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');

    expect(Forecast::orderBy('id')->get()->toArray())->toBe($saved);
    $this->assertModelExists($original);
    $this->assertModelExists($otherMethod);
    $this->assertModelExists($otherQuarter);
});

test('unavailable coverage saves nothing even when sales exist', function (?array $coverage) {
    $product = Product::factory()->create();
    if ($coverage !== null) {
        forecastingCoverage($product, $coverage);
    }
    forecastingSale($product, '2026-07-01', 12);
    $this->mock(CalculateMovingAverage::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'history_unavailable'));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'history_unavailable')
        ->assertInertiaFlash('forecast_result.observations', [])
        ->assertInertiaFlash('forecast_result.forecast_quantity', null)
        ->assertInertiaFlash('forecast_result.generated_at', null);

    $this->assertDatabaseEmpty('forecasts');
})->with([
    'absent' => [null],
    'malformed' => [['start' => 'invalid']],
    'stale' => [['end_exclusive' => '2026-07-01']],
    'gap' => [['unavailable_quarters' => ['2026-04-01']]],
]);

test('insufficient prepared history does not persist or overwrite a forecast', function (string $start, int $count) {
    $product = Product::factory()->create();
    forecastingCoverage($product, ['start' => $start]);
    $saved = Forecast::factory()->for($product)->create(['predicted_demand' => '14.00']);
    $this->mock(CalculateMovingAverage::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'insufficient_history')
            ->where('readiness.covered_quarters', $count));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'insufficient_history')
        ->assertInertiaFlash('forecast_result.covered_quarters', $count)
        ->assertInertiaFlash('forecast_result.message', $count.' completed quarters covered; four are required. No forecast was saved.');

    expect($saved->fresh()->predicted_demand)->toBe('14.00');
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    ['2026-10-01', 0], ['2026-07-01', 1], ['2026-04-01', 2], ['2026-01-01', 3],
]);

test('covered zero sales remain a valid legacy calculation but cannot create a legacy forecast', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $calculated = forecastingPreparedLegacyResult($product);

    expect($calculated['result'])->toMatchArray(['status' => 'ok', 'forecast_quantity' => '0.00']);
    expect(array_column($calculated['prepared']['history']['series'][0]['quarters'], 'quantity_sold'))
        ->toBe([0, 0, 0, 0]);

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');

    $this->assertDatabaseEmpty('forecasts');
});

test('sparse legacy history preserves zero quantity quarters without permitting legacy persistence', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    forecastingSale($product, '2025-10-01', 3);
    $calculated = forecastingPreparedLegacyResult($product);

    expect($calculated['result'])->toMatchArray(['status' => 'ok', 'forecast_quantity' => '0.75']);
    expect(array_column($calculated['prepared']['history']['series'][0]['quarters'], 'quantity_sold'))
        ->toBe([3, 0, 0, 0]);

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');

    $this->assertDatabaseEmpty('forecasts');
});

test('readiness is rechecked during generation without overwriting saved demand', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create(['predicted_demand' => '14.00']);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->where('readiness.status', 'ready'));

    forecastingCoverage($product, ['end_exclusive' => '2026-07-01']);
    $this->mock(CalculateMovingAverage::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'history_unavailable');

    expect($saved->fresh()->predicted_demand)->toBe('14.00');
    $this->assertDatabaseCount('forecasts', 1);
});

test('legacy development calculations remain synthetic without creating legacy forecasts', function () {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $product = Product::where('product_code', 'DEVHIST40INCREASING')->sole();
    $calculated = forecastingPreparedLegacyResult($product);

    expect($calculated['result'])->toMatchArray(['status' => 'ok', 'forecast_quantity' => '32.50']);
    expect($calculated['prepared']['coverage']['source_kind'])->toBe('synthetic_development');
    expect(array_column($calculated['prepared']['history']['series'][0]['quarters'], 'quantity_sold'))
        ->toBe([25, 30, 35, 40]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('readiness.is_synthetic', true)
            ->where('readiness.sales_scope_label', 'Synthetic development transactions'));
    $this->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');

    $this->assertDatabaseEmpty('forecasts');
});

test('invalid generation inputs report field errors without persistence', function (string $field, mixed $value, string $message) {
    $product = Product::factory()->create();
    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index'))
        ->post(route('administration.forecasting.store'), [...forecastingInput($product), $field => $value])
        ->assertSessionHasErrors([$field => $message])
        ->assertInertiaFlashMissing('forecast_result');
    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['product_id', null, 'Select a product.'],
    ['product_id', 'wrong', 'Select a valid product.'],
    ['product_id', 999999, 'The selected product no longer exists.'],
]);

test('generation rejects obsolete or extra configuration controls', function (string $field, mixed $value) {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), [...forecastingInput($product), $field => $value])
        ->assertSessionHasErrors([$field => 'Select a product only. Forecast method and dates are set automatically.'])
        ->assertInertiaFlashMissing('forecast_result');
    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['method', 'linear_trend'], ['method', 'moving_average'], ['method', null],
    ['history_start', '2025-10-01'], ['history_confirmed', true],
    ['history_confirmed', false], ['window_size', 8], ['quarter_count', 8],
    ['target_quarter', '2027-Q1'], ['year', 2025], ['quarter', 1],
]);

test('application timezone controls legacy calculation year rollover without allowing legacy writes', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    $product = Product::factory()->create();
    forecastingCoverage($product, ['start' => '2026-01-01', 'end_exclusive' => '2027-01-01']);
    forecastingSale($product, '2026-12-31', 8);
    forecastingSale($product, '2027-01-01', 900);
    $calculated = forecastingPreparedLegacyResult($product);

    expect($calculated['result'])->toMatchArray([
        'status' => 'ok', 'forecast_quantity' => '2.00', 'timezone' => 'Asia/Manila',
        'source_period' => ['start' => '2026-01-01', 'end_exclusive' => '2027-01-01'],
        'target_quarter' => ['year' => 2027, 'quarter' => 1, 'start' => '2027-01-01', 'end_exclusive' => '2027-04-01'],
    ]);
    expect(array_column($calculated['prepared']['history']['series'][0]['quarters'], 'quantity_sold'))
        ->toBe([0, 0, 0, 8]);

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved: Only additive_holt_winters forecast results can be persisted.'])
        ->assertInertiaFlashMissing('forecast_result');

    $this->assertDatabaseEmpty('forecasts');
});

test('saved review reads stored and legacy results without recalculation or invented quantities', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->mock(CalculateMovingAverage::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');
    Forecast::factory()->for($product)->create(['method' => 'moving_average', 'predicted_demand' => '37.25']);
    Forecast::factory()->for($product)->create(['method' => 'linear_trend', 'predicted_demand' => '52.75']);
    forecastingSale($product, '2026-07-01', 999);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forecasts.data', 2)
            ->where('readiness.status', 'ready')
            ->where('forecasts.data.0.forecast_quantity', '52.75')
            ->where('forecasts.data.0.method_label', 'Linear trend — legacy')
            ->where('forecasts.data.0.is_legacy', true)
            ->where('forecasts.data.0.source_label', 'Not retained with this saved forecast')
            ->where('forecasts.data.1.forecast_quantity', '37.25')
            ->where('forecasts.data.1.method_label', 'Moving average')
            ->where('forecasts.data.1.is_legacy', false)
            ->where('forecasts.data.1.source_label', 'Q4 2025 – Q3 2026 (inferred from four-quarter method)')
            ->missing('forecasts.data.0.observations')
            ->missing('forecasts.data.1.observations')
            ->missingFlash('forecast_result'));

    $this->assertDatabaseCount('forecasts', 2);
});

test('product search and saved review retain inactive selections across pages', function () {
    $product = Product::factory()->for(Category::factory()->state(['is_active' => false]))
        ->create(['name' => 'ZZ Selected', 'product_code' => 'DEVHIST40STABLE', 'is_active' => false]);
    Product::factory()->count(16)->create(['name' => 'Searchable']);
    Forecast::factory()->for($product)->create(['method' => 'linear_trend']);
    Forecast::factory()->for($product)->create(['method' => 'moving_average']);
    Forecast::factory()->create(['method' => 'linear_trend']);
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->get(route('administration.forecasting.index', [
        'q' => 'Searchable', 'page' => 2, 'product_id' => $product->id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.total', 16)
        ->where('selected_product.id', $product->id)
        ->where('selected_product.is_active', false)
        ->where('selected_product.is_synthetic', true)
        ->has('forecasts.data', 2)
        ->where('forecasts.data.0.method', 'moving_average')
        ->where('forecasts.data.1.method', 'linear_trend')
        ->missing('filters.method'));

    $this->get(route('administration.forecasting.index', ['q' => 'DEVHIST40STABLE']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $product->id));
    $this->get(route('administration.forecasting.index', ['q' => "' OR 1=1 --"]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
});

test('invalid review filters report validation errors', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index'))
        ->get(route('administration.forecasting.index', [$field => $value]))
        ->assertSessionHasErrors($field);

    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['product_id', 'wrong'], ['product_id', 999999],
    ['method', 'linear_trend'], ['method', 'moving_average'],
]);

test('saved forecasts have deterministic independent pagination', function () {
    $product = Product::factory()->create();
    foreach (range(2000, 2015) as $year) {
        Forecast::factory()->for($product)->create(['forecast_quarter' => $year.'-Q1']);
    }
    $first = Forecast::oldest('id')->first();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['forecast_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forecasts.data', 1)
            ->where('forecasts.data.0.id', $first->id)
            ->where('forecasts.current_page', 2));
});

test('oversized forecasts return a useful error without overwriting saved demand', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create(['predicted_demand' => '12.00']);
    foreach (range(1, 11) as $item) {
        forecastingSale($product, '2026-07-01', 4000000000);
    }

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors(['forecast' => 'This forecast exceeds the supported quantity and could not be saved.']);

    expect($saved->fresh()->predicted_demand)->toBe('12.00');
    $this->assertDatabaseCount('forecasts', 1);
});
