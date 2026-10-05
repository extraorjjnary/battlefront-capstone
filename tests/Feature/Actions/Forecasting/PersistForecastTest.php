<?php

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\Product;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function persistForecastHistory(int $entityId, array $quantities, string $start = '2025-10-01', string $dimension = 'product'): array
{
    $date = CarbonImmutable::parse($start, 'UTC');
    $quarters = [];
    foreach ($quantities as $quantity) {
        $next = $date->addQuarter();
        $quarters[] = [
            'year' => $date->year,
            'quarter' => $date->quarter,
            'start' => $date->toDateString(),
            'end_exclusive' => $next->toDateString(),
            'quantity_sold' => $quantity,
            'item_revenue' => '0.00',
        ];
        $date = $next;
    }

    return [
        'dimension' => $dimension,
        'timezone' => 'UTC',
        'start' => $start,
        'end_exclusive' => $date->toDateString(),
        'series' => [['entity_id' => $entityId, 'quarters' => $quarters]],
    ];
}

test('persists moving average output against its product and quarter', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->method)->toBe('moving_average');
    expect($forecast->predicted_demand)->toBe('12.50');
    expect($forecast->forecast_quarter)->toBe('2026-Q4');
    expect($forecast->generated_at->toDateTimeString())->toBe('2027-01-15 12:00:00');
    expect($forecast->product->is($product))->toBeTrue();
    expect($product->forecasts()->sole()->is($forecast))->toBeTrue();
    expect(array_keys($forecast->getAttributes()))->toEqualCanonicalizing(['id', 'product_id', 'method', 'predicted_demand', 'forecast_quarter', 'generated_at']);
    $this->assertModelExists($forecast);
    $this->assertDatabaseCount('forecasts', 1);
});

test('preserves historical linear trend rows during moving average inserts reruns and rejected trend writes', function () {
    $product = Product::factory()->create();
    $legacy = Forecast::factory()->for($product)->create([
        'method' => 'linear_trend',
        'predicted_demand' => '25.00',
        'forecast_quarter' => '2026-Q4',
        'generated_at' => '2026-10-15 12:00:00',
    ]);
    $original = $legacy->refresh()->getAttributes();
    $action = app(PersistForecast::class);
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);

    $forecast = $action->execute($result);
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));
    $replacement = $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [8, 8, 8, 8]), $product->id));

    expect(fn () => $action->execute(array_replace($result, ['method' => 'linear_trend'])))->toThrow(InvalidArgumentException::class, 'Only moving_average');

    expect($replacement->id)->toBe($forecast->id);
    expect($replacement->predicted_demand)->toBe('8.00');
    expect($replacement->generated_at->toDateTimeString())->toBe('2027-01-16 12:00:00');
    expect($legacy->refresh()->getAttributes())->toBe($original);
    expect($legacy->product->is($product))->toBeTrue();
    $this->assertDatabaseCount('forecasts', 2);
});

test('rerunning a product method and quarter replaces the result and generation time', function () {
    $product = Product::factory()->create();
    $action = app(PersistForecast::class);
    $first = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [4, 4, 4, 4]), $product->id);
    $second = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [8, 8, 8, 8]), $product->id);
    $original = $action->execute($first);
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));

    $replacement = $action->execute($second);

    expect($replacement->id)->toBe($original->id);
    expect($replacement->predicted_demand)->toBe('8.00');
    expect($replacement->generated_at->toDateTimeString())->toBe('2027-01-16 12:00:00');
    $this->assertDatabaseCount('forecasts', 1);
});

test('keeps distinct target quarters for the same product', function () {
    $product = Product::factory()->create();
    $history = persistForecastHistory($product->id, [5, 10, 15, 20]);
    $action = app(PersistForecast::class);

    $action->execute((new CalculateMovingAverage)->execute($history, $product->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20, 25]), $product->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [8, 8, 8, 8]), $product->id));

    $this->assertDatabaseCount('forecasts', 2);
    expect($product->forecasts()->pluck('forecast_quarter')->all())->toContain('2026-Q4', '2027-Q1');
    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'forecast_quarter' => '2026-Q4', 'predicted_demand' => '8.00']);
    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'forecast_quarter' => '2027-Q1', 'predicted_demand' => '17.50']);
});

test('rejects a valid category calculation at the product-only persistence boundary', function () {
    $category = Category::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($category->id, [5, 10, 15, 20], dimension: 'category'), $category->id);

    expect($result['status'])->toBe('ok');
    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Category forecast results cannot be persisted');
    $this->assertDatabaseCount('forecasts', 0);
});

test('rejects new linear trend writes and every method other than moving average', function (mixed $method) {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    $result['method'] = $method;

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Only moving_average');
    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'legacy linear trend' => ['linear_trend'],
    'unsupported method' => ['seasonal'],
    'missing method' => [null],
    'noncanonical method' => ['MOVING_AVERAGE'],
    'malformed method' => [[]],
]);

test('rejects non-persistable moving average calculator outcomes', function () {
    $product = Product::factory()->create();
    $action = app(PersistForecast::class);
    $insufficient = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15]), $product->id);
    $missingHistory = persistForecastHistory($product->id, [5, 10, 15, 20]);
    $missingHistory['series'] = [];
    $missing = (new CalculateMovingAverage)->execute($missingHistory, $product->id);

    foreach ([$insufficient, $missing] as $result) {
        expect(fn () => $action->execute($result))->toThrow(InvalidArgumentException::class);
    }
    $this->assertDatabaseCount('forecasts', 0);
});

test('rejects malformed output and incomplete or inconsistent periods', function (string $field, mixed $value) {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    if ($field === 'target_start') {
        $result['target_quarter']['start'] = $value;
    } elseif ($field === 'target_end') {
        $result['target_quarter']['end_exclusive'] = $value;
    } elseif ($field === 'source_end') {
        $result['source_period']['end_exclusive'] = $value;
    } elseif ($field === 'source_start') {
        $result['source_period']['start'] = $value;
    } else {
        $result[$field] = $value;
    }

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'missing target' => ['target_quarter', null],
    'missing source' => ['source_period', null],
    'missing dimension' => ['dimension', null],
    'unknown dimension' => ['dimension', 'branch'],
    'unavailable history' => ['status', 'history_unavailable'],
    'unavailable result' => ['status', 'unavailable'],
    'preparation is not a calculation' => ['status', 'ready'],
    'missing status' => ['status', null],
    'incorrect target year' => ['target_quarter', ['year' => 2025, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']],
    'incorrect target quarter' => ['target_quarter', ['year' => 2026, 'quarter' => 3, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']],
    'target not quarter aligned' => ['target_start', '2026-11-01'],
    'target incomplete' => ['target_end', null],
    'target end is not the next boundary' => ['target_end', '2026-12-31'],
    'invalid target year zero' => ['target_start', '0000-10-01'],
    'source gap' => ['source_end', '2026-07-01'],
    'source incomplete' => ['source_start', null],
    'source not quarter aligned' => ['source_start', '2025-11-01'],
    'source too short' => ['source_start', '2026-01-01'],
    'source too long' => ['source_start', '2025-07-01'],
    'source reversed' => ['source_start', '2027-01-01'],
    'missing demand' => ['forecast_quantity', null],
    'float demand' => ['forecast_quantity', 1.25],
    'negative demand' => ['forecast_quantity', '-1.00'],
    'excessive decimal precision' => ['forecast_quantity', '1.234'],
    'insufficient decimal precision' => ['forecast_quantity', '1.2'],
    'scientific notation demand' => ['forecast_quantity', '1e2'],
    'noncanonical demand' => ['forecast_quantity', '01.00'],
    'trailing whitespace demand' => ['forecast_quantity', "1.00\n"],
    'out of range demand' => ['forecast_quantity', '10000000000.00'],
    'invalid timezone' => ['timezone', 'Invalid/Zone'],
    'missing timezone' => ['timezone', null],
    'wrong moving window' => ['window_size', 3],
    'missing moving window' => ['window_size', null],
    'string moving window' => ['window_size', '4'],
    'missing available quarters' => ['available_quarters', null],
    'insufficient available quarters' => ['available_quarters', 3],
    'string available quarters' => ['available_quarters', '4'],
]);

test('rejects a complete calculation whose source includes the current incomplete quarter', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20], '2026-04-01'), $product->id);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('preserves exact decimal values from moving average calculations', function (array $quantities, string $expected) {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, $quantities), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe($expected);
    $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'predicted_demand' => $expected]);
})->with([
    'quarter unit' => [[0, 0, 0, 1], '0.25'],
    'half unit' => [[0, 0, 1, 1], '0.50'],
    'three quarter unit' => [[0, 1, 1, 1], '0.75'],
    'large exact decimal' => [[9999999999, 10000000000, 10000000000, 10000000000], '9999999999.75'],
]);

test('database rejects duplicate keys, missing products, invalid methods, negative demand, and malformed quarters', function (array $attributes) {
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create();

    expect(fn () => DB::table('forecasts')->insert(array_replace([
        'product_id' => $product->id,
        'method' => 'linear_trend',
        'predicted_demand' => '1.00',
        'forecast_quarter' => '2026-Q4',
        'generated_at' => now(),
    ], $attributes)))->toThrow(QueryException::class);

    $this->assertModelExists($forecast);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'unique key' => [['method' => 'moving_average']],
    'foreign key' => [['product_id' => 999999]],
    'unsupported method' => [['method' => 'unsupported']],
    'uppercase method' => [['method' => 'LINEAR_TREND']],
    'method trailing space' => [['method' => 'linear_trend ']],
    'negative demand' => [['predicted_demand' => '-1.00']],
    'quarter format' => [['forecast_quarter' => '2026-Q9']],
    'lowercase quarter' => [['forecast_quarter' => '2026-q4']],
    'year zero' => [['forecast_quarter' => '0000-Q1']],
    'required demand' => [['predicted_demand' => null]],
    'required generation time' => [['generated_at' => null]],
    'required product' => [['product_id' => null]],
]);

test('database protects existing forecasts on updates and product deletion', function () {
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create();

    expect(fn () => DB::table('forecasts')->where('id', $forecast->id)->update(['predicted_demand' => '-1.00']))->toThrow(QueryException::class);
    expect(fn () => $product->delete())->toThrow(QueryException::class);

    $this->assertModelExists($forecast);
    $this->assertModelExists($product);
});

test('database rejects noncanonical values when updating an existing forecast', function (array $attributes) {
    $forecast = Forecast::factory()->create();
    $original = $forecast->getAttributes();

    expect(fn () => DB::table('forecasts')->where('id', $forecast->id)->update($attributes))->toThrow(QueryException::class);

    expect($forecast->refresh()->getAttributes())->toEqual($original);
})->with([
    'uppercase method' => [['method' => 'LINEAR_TREND']],
    'trailing space' => [['method' => 'linear_trend ']],
    'lowercase quarter' => [['forecast_quarter' => '2026-q4']],
    'year zero' => [['forecast_quarter' => '0000-Q1']],
]);

test('persists explicit zero demand from moving average', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [0, 0, 0, 0]), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('0.00');
    $this->assertModelExists($forecast);
});

test('persists covered zero demand from ready product preparation and the moving average calculator', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => [
        'start' => '2026-01-01',
        'end_exclusive' => '2027-01-01',
        'unavailable_quarters' => [],
        'timezone' => 'UTC',
        'source_kind' => 'operational_prepared',
        'sales_scope' => 'captured_system_transactions',
    ]]]);
    $preparation = app(ProductForecastPreparationService::class)->prepare($product);
    expect($preparation['status'])->toBe('ready');
    $result = (new CalculateMovingAverage)->execute($preparation['history'], $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('0.00');
    expect($forecast->forecast_quarter)->toBe('2027-Q1');
    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'method' => 'moving_average', 'forecast_quarter' => '2027-Q1', 'predicted_demand' => '0.00']);
    $this->assertDatabaseCount('forecasts', 1);
});

test('persists the maximum finalized demand without recalculating it', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [0, 0, 0, 0]), $product->id);
    $result['forecast_quantity'] = '9999999999.99';

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('9999999999.99');
    $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'predicted_demand' => '9999999999.99']);
});

test('rejects invalid reruns without changing an existing forecast', function (array $attributes) {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    $action = app(PersistForecast::class);
    $forecast = $action->execute($result);
    $original = $forecast->getAttributes();
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));

    expect(fn () => $action->execute(array_replace($result, $attributes)))->toThrow(InvalidArgumentException::class);

    expect($forecast->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'linear trend' => [['method' => 'linear_trend']],
    'category' => [['dimension' => 'category']],
    'unavailable' => [['status' => 'history_unavailable']],
    'insufficient' => [['status' => 'insufficient_history']],
    'bad period' => [['source_period' => null]],
    'bad quantity' => [['forecast_quantity' => '-1.00']],
]);

test('rejects real moving average results exceeding decimal storage capacity', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, array_fill(0, 4, 10000000000)), $product->id);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('keeps separate forecasts for different products sharing a method and quarter', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $action = app(PersistForecast::class);

    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($first->id, [4, 4, 4, 4]), $first->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($second->id, [8, 8, 8, 8]), $second->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($first->id, [12, 12, 12, 12]), $first->id));

    expect($first->forecasts()->sole()->predicted_demand)->toBe('12.00');
    expect($second->forecasts()->sole()->predicted_demand)->toBe('8.00');
    $this->assertDatabaseCount('forecasts', 2);
});

test('rejects invalid product identity through the persistence action', function (mixed $id) {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [4, 4, 4, 4]), $product->id);
    $result['entity_id'] = $id;

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with(['missing' => [null], 'zero' => [0], 'negative' => [-1], 'string' => ['1'], 'nonexistent' => [999999]]);
