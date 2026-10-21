<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

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
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage', 'reason' => 'Broken packaging observed',
    ])->assertSessionHasNoErrors();

    $inventoryAfterReport = $product->inventory->fresh();
    expect($inventoryAfterReport->quantity)->toBe($before - 1)
        ->and($inventoryAfterReport->quarantined_quantity)->toBe(1);
    $id = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $this->actingAs($manager)->get(route('administration.damage-reports.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/DamageReports')->where('reports.data.0.held_quantity', 1));
    $this->actingAs($staff)->post(route('administration.damage-reports.decide', $id), [
        'disposition' => 'write_off', 'reason' => 'Unit cannot be sold safely',
    ])->assertForbidden();
    $this->actingAs($manager)->post(route('administration.damage-reports.decide', $id), [
        'disposition' => 'write_off', 'reason' => 'Unit cannot be sold safely',
    ])->assertSessionHasErrors('evidence');
    $this->actingAs($manager)->post(route('administration.evidence.store', ['subjectType' => 'damage_report', 'subjectId' => $id]), [
        'files' => [UploadedFile::fake()->image('damage.jpg')],
    ])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('administration.damage-reports.decide', $id), [
        'disposition' => 'write_off', 'reason' => 'Unit cannot be sold safely',
    ])->assertSessionHasNoErrors();
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseHas('inventory_movements', ['reference_id' => $id, 'kind' => 'damage_write_off']);
    $this->actingAs($manager)->get(route('administration.damage-reports.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/DamageReports')->where('reports.data.0.held_quantity', 0));
});

test('damage cannot quarantine more than sellable quantity', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $quantity = $product->inventory->quantity;

    $this->actingAs($owner)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => $quantity + 1, 'reason_code' => 'other', 'reason' => 'Physical count mismatch',
    ])->assertSessionHasErrors('quantity');

    expect($product->inventory->fresh()->quantity)->toBe($quantity);
    expect(DB::table('damage_reports')->where('product_id', $product->id)->count())->toBe(0);
});

test('damaged stock cannot return to sellable inventory without inspection confirmation', function () {
    $reporter = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $sellableBefore = $product->inventory->quantity;

    $this->actingAs($reporter)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id,
        'quantity' => 1,
        'reason_code' => 'defect_discovered',
        'reason' => 'A defect was discovered during storage',
    ])->assertSessionHasNoErrors();
    $reportId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');

    $this->actingAs($reviewer)->post(route('administration.damage-reports.decide', $reportId), [
        'disposition' => 'release',
        'reason' => 'Inspection found the product safe to sell',
    ])->assertSessionHasErrors('inspection_confirmed');

    $this->actingAs($reviewer)->post(route('administration.damage-reports.decide', $reportId), [
        'disposition' => 'release',
        'inspection_confirmed' => '1',
        'reason' => 'Inspection found the product safe to sell',
    ])->assertSessionHasNoErrors();

    expect($product->inventory->fresh()->quantity)->toBe($sellableBefore)
        ->and($product->inventory->fresh()->quarantined_quantity)->toBe(0);
});

test('damage reports can be searched by product, requester, or reason', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Search damage product']);
    $reportId = DB::table('damage_reports')->insertGetId([
        'product_id' => $product->id,
        'requester_id' => $administrator->id,
        'quantity' => 1,
        'status' => 'pending',
        'reason' => 'Search damage reason',
        'created_at' => now()->subDays(4),
        'updated_at' => now(),
    ]);

    $this->actingAs($administrator)->get(route('administration.damage-reports.index', ['q' => 'Search damage product', 'overdue' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.q', 'Search damage product')
            ->where('filters.overdue', true)
            ->where('reports.total', 1)
            ->where('reports.data.0.id', $reportId));
});
