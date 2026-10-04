<?php

use App\Actions\Forecasting\CalculateLinearTrend;
use App\Actions\Forecasting\CalculateMovingAverage;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
    $this->assertModelExists($forecast);
    $this->assertDatabaseCount('forecasts', 1);
});

test('persists finalized linear trend output and leaves diagnostics out of the schema', function () {
    $product = Product::factory()->create();
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->method)->toBe('linear_trend');
    expect($forecast->predicted_demand)->toBe('25.00');
    expect($forecast->forecast_quarter)->toBe('2026-Q4');
    expect(array_keys($forecast->getAttributes()))->toEqualCanonicalizing(['id', 'product_id', 'method', 'predicted_demand', 'forecast_quarter', 'generated_at']);
    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'method' => 'linear_trend', 'predicted_demand' => '25.00']);
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

test('keeps distinct methods and target quarters for the same product', function () {
    $product = Product::factory()->create();
    $history = persistForecastHistory($product->id, [5, 10, 15, 20]);
    $action = app(PersistForecast::class);

    $action->execute((new CalculateMovingAverage)->execute($history, $product->id));
    $action->execute((new CalculateLinearTrend)->execute($history, $product->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20, 25]), $product->id));

    $this->assertDatabaseCount('forecasts', 3);
    expect($product->forecasts()->pluck('forecast_quarter')->all())->toContain('2026-Q4', '2027-Q1');
});

test('rejects a valid category calculation at the product-only persistence boundary', function (string $calculator) {
    $category = Category::factory()->create();
    $result = (new $calculator)->execute(persistForecastHistory($category->id, [5, 10, 15, 20], dimension: 'category'), $category->id);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Category forecast results cannot be persisted');
    $this->assertDatabaseCount('forecasts', 0);
})->with([CalculateMovingAverage::class, CalculateLinearTrend::class]);

test('rejects unsupported methods and non-persistable calculator outcomes', function (string $calculator) {
    $product = Product::factory()->create();
    $action = app(PersistForecast::class);
    $valid = (new $calculator)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    $unsupported = array_replace($valid, ['method' => 'seasonal']);
    $insufficient = (new $calculator)->execute(persistForecastHistory($product->id, [5, 10, 15]), $product->id);
    $missingHistory = persistForecastHistory($product->id, [5, 10, 15, 20]);
    $missingHistory['series'] = [];
    $missing = (new $calculator)->execute($missingHistory, $product->id);

    foreach ([$unsupported, $insufficient, $missing] as $result) {
        expect(fn () => $action->execute($result))->toThrow(InvalidArgumentException::class);
    }
    $this->assertDatabaseCount('forecasts', 0);
})->with([CalculateMovingAverage::class, CalculateLinearTrend::class]);

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
    'incorrect target year' => ['target_quarter', ['year' => 2025, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']],
    'target not quarter aligned' => ['target_start', '2026-11-01'],
    'target incomplete' => ['target_end', null],
    'source gap' => ['source_end', '2026-07-01'],
    'source not quarter aligned' => ['source_start', '2025-11-01'],
    'source too short' => ['source_start', '2026-01-01'],
    'missing demand' => ['forecast_quantity', null],
    'float demand' => ['forecast_quantity', 1.25],
    'negative demand' => ['forecast_quantity', '-1.00'],
    'excessive decimal precision' => ['forecast_quantity', '1.234'],
    'out of range demand' => ['forecast_quantity', '10000000000.00'],
    'invalid timezone' => ['timezone', 'Invalid/Zone'],
    'wrong moving window' => ['window_size', 3],
]);

test('rejects a complete calculation whose source includes the current incomplete quarter', function () {
    $product = Product::factory()->create();
    $result = (new CalculateMovingAverage)->execute(persistForecastHistory($product->id, [5, 10, 15, 20], '2026-04-01'), $product->id);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('rejects incomplete or inconsistent linear calculation metadata', function (string $field, mixed $value) {
    $product = Product::factory()->create();
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    $result[$field] = $value;

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'missing raw projection' => ['raw_forecast_quantity', null],
    'malformed slope' => ['slope', '1.25'],
    'missing clamp flag' => ['was_clamped', null],
    'incorrect available quarters' => ['available_quarters', 5],
    'inconsistent positive clamp' => ['was_clamped', true],
]);

test('preserves exact small decimal values from a linear calculation', function () {
    $product = Product::factory()->create();
    $quantities = array_fill(0, 16, 0);
    $quantities[6] = 1;
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, $quantities, '2022-10-01'), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($result['forecast_quantity'])->toBe('0.03');
    expect($forecast->predicted_demand)->toBe('0.03');
    $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'predicted_demand' => '0.03']);
});

test('persists zero after linear projection is clamped and rejects inconsistent clamp metadata', function () {
    $product = Product::factory()->create();
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, [9, 6, 3, 0]), $product->id);
    $action = app(PersistForecast::class);

    $forecast = $action->execute($result);

    expect($result['raw_forecast_quantity'])->toBe('-3.000000');
    expect($result['was_clamped'])->toBeTrue();
    expect($forecast->predicted_demand)->toBe('0.00');
    $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'predicted_demand' => '0.00']);

    $original = $forecast->getAttributes();
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));

    foreach ([array_replace($result, ['was_clamped' => false]), array_replace($result, ['raw_forecast_quantity' => null]), array_replace($result, ['forecast_quantity' => '1.00'])] as $invalid) {
        expect(fn () => $action->execute($invalid))->toThrow(InvalidArgumentException::class);
    }
    $this->assertDatabaseCount('forecasts', 1);
    expect($forecast->refresh()->getAttributes())->toBe($original);
});

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

test('persists explicit zero demand from either calculator', function (string $calculator) {
    $product = Product::factory()->create();
    $result = (new $calculator)->execute(persistForecastHistory($product->id, [0, 0, 0, 0]), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('0.00');
    $this->assertModelExists($forecast);
})->with([CalculateMovingAverage::class, CalculateLinearTrend::class]);

test('preserves a clamp decision when the raw projection has rounded to zero', function () {
    $product = Product::factory()->create();
    $quantities = array_fill(0, 4000, 0);
    $quantities[1332] = 1;
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, $quantities, '1026-10-01'), $product->id);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($result['raw_forecast_quantity'])->toBe('0.000000');
    expect($result['was_clamped'])->toBeTrue();
    expect($forecast->predicted_demand)->toBe('0.00');
});

test('accepts independently rounded raw and finalized projections at rounding boundaries', function (string $raw, string $finalized) {
    $product = Product::factory()->create();
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, [0, 0, 0, 0]), $product->id);
    $result['raw_forecast_quantity'] = $raw;
    $result['forecast_quantity'] = $finalized;

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe($finalized);
})->with([
    'raw rounds up across half cent' => ['0.005000', '0.00'],
    'exact half cent' => ['0.005000', '0.01'],
    'maximum stored demand' => ['9999999999.990000', '9999999999.99'],
]);

test('rejects inconsistent raw and finalized projections without changing an existing forecast', function (string $raw, string $finalized) {
    $product = Product::factory()->create();
    $result = (new CalculateLinearTrend)->execute(persistForecastHistory($product->id, [5, 10, 15, 20]), $product->id);
    $action = app(PersistForecast::class);
    $forecast = $action->execute($result);
    $original = $forecast->getAttributes();
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));
    $result['raw_forecast_quantity'] = $raw;
    $result['forecast_quantity'] = $finalized;

    expect(fn () => $action->execute($result))->toThrow(InvalidArgumentException::class);

    expect($forecast->refresh()->getAttributes())->toBe($original);
})->with([
    'clearly inconsistent' => ['25.000000', '999.00'],
    'below rounding interval' => ['0.004999', '0.01'],
    'above rounding interval' => ['0.005001', '0.00'],
]);

test('rejects real calculator results exceeding decimal storage capacity', function (string $calculator) {
    $product = Product::factory()->create();
    $result = (new $calculator)->execute(persistForecastHistory($product->id, array_fill(0, 4, 10000000000)), $product->id);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with([CalculateMovingAverage::class, CalculateLinearTrend::class]);

test('keeps separate forecasts for different products sharing a method and quarter', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $action = app(PersistForecast::class);

    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($first->id, [4, 4, 4, 4]), $first->id));
    $action->execute((new CalculateMovingAverage)->execute(persistForecastHistory($second->id, [8, 8, 8, 8]), $second->id));

    expect($first->forecasts()->sole()->predicted_demand)->toBe('4.00');
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
