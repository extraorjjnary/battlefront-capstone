<?php

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('purchase name snapshots survive catalog renaming and cannot be edited', function () {
    $product = Product::factory()->create(['name' => 'Original name']);
    $item = OrderItem::factory()->for($product)->create(['product_name_at_time' => $product->name]);
    $product->update(['name' => 'Renamed catalog item']);

    expect($item->refresh()->purchasedProductName())->toBe('Original name');
    expect(fn () => $item->update(['product_name_at_time' => 'Rewritten history']))->toThrow(DomainException::class);
    expect($item->refresh()->product_name_at_time)->toBe('Original name');
});

test('legacy purchases fall back to retained catalog names without inventing snapshots', function () {
    $item = OrderItem::factory()->create();
    $item->product->delete();

    expect($item->refresh()->product_name_at_time)->toBeNull()
        ->and($item->purchasedProductName())->toBe($item->product->name);
});

test('the additive purchase name migration retains legacy rows and historical deletion protection', function () {
    $item = OrderItem::factory()->create();
    $before = $item->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_10_22_044600_add_product_name_at_time_to_order_items_table.php');
    $migration->down();
    $migration->up();

    expect($item->refresh()->getAttributes())->toEqual($before);
    expect(fn () => DB::table('order_items')->where('id', $item->id)->delete())->toThrow(QueryException::class);
});

test('rollback refuses to discard recorded purchase names', function () {
    $item = OrderItem::factory()->create(['product_name_at_time' => 'Recorded purchase name']);
    $migration = require database_path('migrations/2026_10_22_044600_add_product_name_at_time_to_order_items_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Purchase name snapshots must not be discarded.');
    expect($item->refresh()->product_name_at_time)->toBe('Recorded purchase name');
});
