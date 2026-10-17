<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('reported damage immediately quarantines stock and another person decides its disposition', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;

    $this->actingAs($staff)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Broken packaging observed',
    ])->assertSessionHasNoErrors();

    $inventoryAfterReport = $product->inventory->fresh();
    expect($inventoryAfterReport->quantity)->toBe($before - 1)
        ->and($inventoryAfterReport->quarantined_quantity)->toBe(1);
    $id = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $this->actingAs($staff)->post(route('administration.damage-reports.decide', $id), [
        'disposition' => 'write_off',
    ])->assertForbidden();
    $this->actingAs($manager)->post(route('administration.damage-reports.decide', $id), [
        'disposition' => 'write_off',
    ])->assertSessionHasNoErrors();
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseHas('inventory_movements', ['reference_id' => $id, 'kind' => 'damage_write_off']);
});

test('damage cannot quarantine more than sellable quantity', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $quantity = $product->inventory->quantity;

    $this->actingAs($owner)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => $quantity + 1, 'reason' => 'Physical count mismatch',
    ])->assertSessionHasErrors('quantity');

    expect($product->inventory->fresh()->quantity)->toBe($quantity);
    expect(DB::table('damage_reports')->where('product_id', $product->id)->count())->toBe(0);
});
