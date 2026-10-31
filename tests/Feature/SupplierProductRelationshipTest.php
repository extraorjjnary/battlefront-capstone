<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('supplier product links support multiple suppliers without changing stock or duplicating pairs', function () {
    $product = Product::factory()->available()->create();
    $first = DB::table('suppliers')->insertGetId(['name' => 'First supplier']);
    $second = DB::table('suppliers')->insertGetId(['name' => 'Second supplier']);
    DB::table('product_supplier')->insert([
        ['supplier_id' => $first, 'product_id' => $product->id],
        ['supplier_id' => $second, 'product_id' => $product->id],
    ]);

    expect(fn () => DB::table('product_supplier')->insert(['supplier_id' => $first, 'product_id' => $product->id]))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('product_supplier', 2);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 10, 'quarantined_quantity' => 0]);
});

test('manual supplier return rejects an unrelated product before changing inventory or history', function () {
    $assigned = Product::factory()->available()->create();
    $unrelated = Product::factory()->available()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Assigned supplier']);
    DB::table('product_supplier')->insert(['supplier_id' => $supplier, 'product_id' => $assigned->id]);
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplier, 'product_id' => $unrelated->id, 'quantity' => 2, 'reason_code' => 'defective',
    ])->assertRedirect()->assertSessionHasErrors(['product_id' => 'Select a product linked to this supplier.']);

    $this->assertDatabaseCount('supplier_returns', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'supplier_return.requested']);
    $this->assertDatabaseHas('inventories', ['product_id' => $unrelated->id, 'quantity' => 10, 'quarantined_quantity' => 0]);
});

test('manual supplier return accepts a linked product and preserves suppliers without assignments', function (bool $hasAssignment) {
    $product = Product::factory()->available()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Return supplier']);
    if ($hasAssignment) {
        DB::table('product_supplier')->insert(['supplier_id' => $supplier, 'product_id' => $product->id]);
    }
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplier, 'product_id' => $product->id, 'quantity' => 2, 'reason_code' => 'defective',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('supplier_returns', ['supplier_id' => $supplier, 'product_id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 8, 'quarantined_quantity' => 2]);
})->with([true, false]);

test('saved links remain visible when linked stock is zero so the picker cannot fall back to unrelated products', function () {
    $product = Product::factory()->available()->create();
    $product->inventory->update(['quantity' => 0]);
    $unrelated = Product::factory()->available()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Out of stock supplier']);
    DB::table('product_supplier')->insert(['supplier_id' => $supplier, 'product_id' => $product->id]);
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->get(route('administration.supplier-returns.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('products', 1)->where('products.0.id', $unrelated->id)
        ->has('supplier_product_links', 1)
        ->where('supplier_product_links.0.supplier_id', $supplier)
        ->where('supplier_product_links.0.product_id', $product->id));
});

test('inactive suppliers remain rejected even when a product is assigned', function () {
    $product = Product::factory()->available()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Inactive supplier', 'is_active' => false]);
    DB::table('product_supplier')->insert(['supplier_id' => $supplier, 'product_id' => $product->id]);
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplier, 'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'defective',
    ])->assertRedirect()->assertSessionHasErrors('supplier_id');

    $this->assertDatabaseCount('supplier_returns', 0);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 10]);
});

test('rollback refuses to discard populated supplier relationships', function () {
    $product = Product::factory()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Saved supplier']);
    DB::table('product_supplier')->insert(['supplier_id' => $supplier, 'product_id' => $product->id]);
    $migration = require database_path('migrations/2026_10_31_152936_create_product_supplier_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot remove saved supplier product relationships');

    $this->assertDatabaseHas('product_supplier', ['supplier_id' => $supplier, 'product_id' => $product->id]);
});
