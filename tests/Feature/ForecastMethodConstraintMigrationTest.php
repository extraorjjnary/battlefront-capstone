<?php

use App\Models\Forecast;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function forecastMethodMigration(): Migration
{
    return require database_path('migrations/2026_10_05_154202_allow_additive_holt_winters_forecast_method.php');
}

beforeEach(function () {
    $original = DB::getDefaultConnection();
    config(['forecast_constraints_original_connection' => $original]);
    $connection = config("database.connections.{$original}");
    if ($connection['driver'] === 'mysql') {
        if (getenv('FORECAST_METHOD_TEST_DATABASE') !== 'battlefront_ext45_constraints_test') {
            $this->markTestSkipped('MySQL migration tests require the dedicated battlefront_ext45_constraints_test database.');
        }
        $connection['database'] = 'battlefront_ext45_constraints_test';
    } else {
        $connection['database'] = ':memory:';
    }
    $connection['url'] = null;
    config(['database.connections.forecast_constraints' => $connection]);
    DB::setDefaultConnection('forecast_constraints');
    Schema::dropAllTables();
    foreach (glob(database_path('migrations/*.php')) as $path) {
        if (str_ends_with($path, 'allow_additive_holt_winters_forecast_method.php')) {
            continue;
        }
        $migration = require $path;
        $migration->up();
    }
});

afterEach(function () {
    DB::setDefaultConnection(config('forecast_constraints_original_connection'));
    DB::purge('forecast_constraints');
});

test('forward migration preserves legacy rows and allows all three canonical methods', function () {
    $product = Product::factory()->create();
    $legacy = Forecast::factory()->for($product)->count(2)->sequence(
        ['method' => 'moving_average'], ['method' => 'linear_trend'],
    )->create();
    $original = $legacy->map(fn (Forecast $forecast): array => $forecast->refresh()->getAttributes())->all();
    $attributes = Forecast::factory()->for($product)->make(['method' => 'additive_holt_winters'])->getAttributes();
    expect(fn () => DB::table('forecasts')->insert($attributes))->toThrow(QueryException::class);

    forecastMethodMigration()->up();
    $forecast = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters']);

    $this->assertModelExists($forecast);
    expect($legacy->map(fn (Forecast $forecast): array => $forecast->refresh()->getAttributes())->all())->toBe($original);
    $this->assertDatabaseCount('forecasts', 3);
});

test('forward migration enforces canonical methods on both insert and update', function (string $method) {
    forecastMethodMigration()->up();
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters']);
    $original = $forecast->refresh()->getAttributes();
    $attributes = Forecast::factory()->for($product)->make(['method' => $method])->getAttributes();

    expect(fn () => DB::table('forecasts')->insert($attributes))->toThrow(QueryException::class);
    expect(fn () => DB::table('forecasts')->where('id', $forecast->id)->update(['method' => $method]))->toThrow(QueryException::class);

    expect($forecast->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('forecasts', 1);
})->with(['ADDITIVE_HOLT_WINTERS', 'additive_holt_winters ', 'additive_holt_winters'."\n", 'unsupported']);

test('forward migration preserves demand quarter uniqueness and foreign key protections', function (array $attributes) {
    forecastMethodMigration()->up();
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters']);
    $invalid = Forecast::factory()->for($product)->make(array_replace([
        'method' => 'additive_holt_winters', 'forecast_quarter' => '2027-Q1',
    ], $attributes))->getAttributes();

    expect(fn () => DB::table('forecasts')->insert($invalid))->toThrow(QueryException::class);

    $this->assertModelExists($forecast);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'unique key' => [['forecast_quarter' => '2026-Q4']],
    'foreign key' => [['product_id' => 999999]],
    'negative demand' => [['predicted_demand' => '-1.00']],
    'quarter number' => [['forecast_quarter' => '2027-Q5']],
    'quarter case' => [['forecast_quarter' => '2027-q1']],
    'quarter year zero' => [['forecast_quarter' => '0000-Q1']],
    'quarter separator' => [['forecast_quarter' => '2027_Q1']],
]);

test('rollback refuses to narrow constraints while Holt Winters rows exist', function () {
    $migration = forecastMethodMigration();
    $migration->up();
    $forecast = Forecast::factory()->create(['method' => 'additive_holt_winters']);
    $original = $forecast->refresh()->getAttributes();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back forecast methods');

    expect($forecast->refresh()->getAttributes())->toBe($original);
    $forecast->update(['predicted_demand' => '1.00']);
    $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'method' => 'additive_holt_winters', 'predicted_demand' => '1.00']);
    Forecast::factory()->create(['method' => 'additive_holt_winters']);
    $this->assertDatabaseCount('forecasts', 2);
});

test('rollback without Holt Winters rows restores legacy constraints and can be reapplied', function () {
    $legacy = Forecast::factory()->create(['method' => 'linear_trend']);
    $original = $legacy->refresh()->getAttributes();
    $migration = forecastMethodMigration();
    $migration->up();

    $migration->down();

    $attributes = Forecast::factory()->for($legacy->product)->make(['method' => 'additive_holt_winters'])->getAttributes();
    expect(fn () => DB::table('forecasts')->insert($attributes))->toThrow(QueryException::class);
    expect(fn () => DB::table('forecasts')->where('id', $legacy->id)->update(['method' => 'additive_holt_winters']))->toThrow(QueryException::class);
    expect($legacy->refresh()->getAttributes())->toBe($original);
    $migration->up();
    DB::table('forecasts')->insert($attributes);
    $this->assertDatabaseCount('forecasts', 2);
});
