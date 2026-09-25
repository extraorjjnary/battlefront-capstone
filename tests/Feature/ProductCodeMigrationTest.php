<?php

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('product codes are required and unique ignoring case at the database boundary', function () {
    $product = Product::factory()->create(['product_code' => 'AbC123']);

    expect(fn () => $product->update(['product_code' => null]))->toThrow(QueryException::class);
    expect(fn () => Product::factory()->create(['product_code' => 'abc123']))->toThrow(QueryException::class);

    expect($product->refresh()->product_code)->toBe('AbC123');
});

test('migration backfills recognized demo identities without changing their product IDs', function () {
    $product = Product::factory()->create([
        'name' => '[DEMO] NVIDIA Atlas Graphics Card',
        'description' => 'Synthetic development sample only; not a Battlefront stocked product.',
    ]);
    $migration = require database_path('migrations/2026_09_25_152705_require_product_codes_and_track_catalog_imports.php');
    $migration->down();
    DB::table('products')->where('id', $product->id)->update(['product_code' => null]);

    $migration->up();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'product_code' => 'LEGACY'.$product->id, 'is_catalog_imported' => false]);
    expect(fn () => $product->refresh()->update(['price' => '-1.00']))->toThrow(QueryException::class);
});

test('migration refuses unknown legacy products instead of inventing codes', function () {
    $product = Product::factory()->create();
    $migration = require database_path('migrations/2026_09_25_152705_require_product_codes_and_track_catalog_imports.php');
    $migration->down();
    DB::table('products')->where('id', $product->id)->update(['product_code' => null]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'needs a verified product code');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'product_code' => null]);
    expect(Schema::hasColumn('products', 'is_catalog_imported'))->toBeFalse();
});

test('migration detects case collisions before applying any backfill', function () {
    $first = Product::factory()->create(['product_code' => 'ABC']);
    $second = Product::factory()->create(['product_code' => 'OTHER']);
    $migration = require database_path('migrations/2026_09_25_152705_require_product_codes_and_track_catalog_imports.php');
    $migration->down();
    DB::table('products')->where('id', $second->id)->update(['product_code' => 'abc']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'duplicate product code');

    $this->assertDatabaseHas('products', ['id' => $first->id, 'product_code' => 'ABC']);
    expect(Schema::hasColumn('products', 'is_catalog_imported'))->toBeFalse();
});

test('rollback retains generated legacy codes and the existing price constraints', function () {
    $product = Product::factory()->create(['product_code' => 'LEGACY42', 'price' => '100.00']);
    $migration = require database_path('migrations/2026_09_25_152705_require_product_codes_and_track_catalog_imports.php');

    $migration->down();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'product_code' => 'LEGACY42']);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['discount_price' => '100.00']))
        ->toThrow(QueryException::class);
});
