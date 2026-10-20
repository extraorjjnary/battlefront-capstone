<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('stock reconciliation identifies movement and quarantine gaps without changing stock', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $inventory = $product->inventory;
    DB::table('inventory_movements')->insert([
        'product_id' => $product->id,
        'actor_id' => $owner->id,
        'kind' => 'approved_adjustment',
        'reference_type' => 'inventory_adjustment_request',
        'reference_id' => 1,
        'sellable_before' => $inventory->quantity - 1,
        'sellable_delta' => 1,
        'sellable_after' => $inventory->quantity,
        'quarantined_before' => 0,
        'quarantined_delta' => 0,
        'quarantined_after' => 0,
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.stock-reconciliation', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/StockReconciliation')
            ->where('inventories.data.0.status', 'matched')
            ->where('movements.total', 1));

    $inventory->quarantined_quantity = 2;
    $inventory->save();
    $this->actingAs($owner)->get(route('administration.reports.stock-reconciliation', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->where('inventories.data.0.status', 'discrepancy')
            ->where('inventories.data.0.case_expected', 0));
    expect($inventory->fresh()->quarantined_quantity)->toBe(2);
});

test('customers cannot inspect stock movement history', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('administration.reports.stock-reconciliation'))->assertForbidden();
});

test('stock reconciliation can search by product name or product code', function () {
    $owner = User::factory()->administrator()->create();
    $match = Product::factory()->available()->create(['name' => 'Radeon Search Target', 'product_code' => 'GPU-SEARCH-1']);
    Product::factory()->available()->create(['name' => 'Other product', 'product_code' => 'OTHER-1']);

    $this->actingAs($owner)->get(route('administration.reports.stock-reconciliation', ['q' => 'GPU-SEARCH-1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'GPU-SEARCH-1')
            ->where('inventories.total', 1)
            ->where('inventories.data.0.product_id', $match->id));
});

test('linked supplier return owns held stock after a damage decision without double counting', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $inventory = $product->inventory;
    $before = $inventory->quantity;
    $inventory->quantity -= 2;
    $inventory->quarantined_quantity = 2;
    $inventory->save();
    $damageId = DB::table('damage_reports')->insertGetId([
        'product_id' => $product->id, 'requester_id' => $owner->id,
        'quantity' => 2, 'status' => 'approved', 'disposition' => 'supplier_return',
        'reason' => 'Broken packaging', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Test supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('supplier_returns')->insert([
        'supplier_id' => $supplierId, 'product_id' => $product->id,
        'damage_report_id' => $damageId, 'requester_id' => $owner->id,
        'quantity' => 2, 'status' => 'pending', 'reason' => 'Supplier inspection',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('inventory_movements')->insert([
        'product_id' => $product->id, 'actor_id' => $owner->id,
        'kind' => 'damage_hold', 'reference_type' => 'damage_report', 'reference_id' => $damageId,
        'sellable_before' => $before, 'sellable_delta' => -2, 'sellable_after' => $inventory->quantity,
        'quarantined_before' => 0, 'quarantined_delta' => 2, 'quarantined_after' => 2,
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.stock-reconciliation'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('inventories.data.0.case_expected', 2)
            ->where('inventories.data.0.status', 'matched'));
});
