<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('manager can update the reorder level separately while staff cannot', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $inventory = Product::factory()->available()->create()->inventory;
    $payload = ['reorder_level' => 15, 'reason' => 'Supplier lead time changed'];

    $this->actingAs($staff)->patch(route('administration.inventory.reorder-level.update', $inventory), $payload)->assertForbidden();
    $this->actingAs($manager)->patch(route('administration.inventory.reorder-level.update', $inventory), $payload)->assertSessionHasNoErrors();
    expect($inventory->fresh()->reorder_level)->toBe(15);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.reorder_level.updated', 'subject_id' => $inventory->id]);
});

test('manual stock changes require a different authorized reviewer and preserve a movement', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;

    $this->actingAs($staff)->patch(route('administration.inventory.update', $product->inventory), [
        'quantity' => $before + 4,
        'reorder_level' => $product->inventory->reorder_level,
        'reason' => 'Physical stock count correction',
    ])->assertSessionHasNoErrors();

    expect($product->inventory->fresh()->quantity)->toBe($before);
    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');

    $this->actingAs($staff)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count checked on site',
    ])->assertForbidden();

    $this->actingAs($manager)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count checked on site',
    ])->assertSessionHasNoErrors();

    expect($product->inventory->fresh()->quantity)->toBe($before + 4);
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $product->id, 'reference_id' => $requestId,
        'kind' => 'approved_adjustment', 'sellable_delta' => 4,
    ]);

    $this->actingAs($manager)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Repeat approval attempt',
    ])->assertSessionHasErrors('decision');

    expect(DB::table('inventory_movements')->where('reference_id', $requestId)->count())->toBe(1);
});

test('owners cannot approve their own stock request', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($owner)->patch(route('administration.inventory.update', $product->inventory), [
        'quantity' => $product->inventory->quantity + 1,
        'reorder_level' => $product->inventory->reorder_level,
        'reason' => 'Physical count correction',
    ])->assertSessionHasNoErrors();

    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $this->actingAs($owner)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count checked on site',
    ])->assertSessionHasErrors('decision');

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $requestId, 'status' => 'pending']);
});

test('opening stock stays unavailable until a second person approves it', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->create();

    $this->actingAs($requester)->post(route('administration.products.inventory.store', $product), [
        'quantity' => 7,
        'reorder_level' => 2,
        'reason' => 'Opening count on shelf',
    ])->assertSessionHasNoErrors();

    expect($product->inventory()->exists())->toBeFalse();
    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Opening count verified',
    ])->assertSessionHasNoErrors();

    expect($product->inventory()->first()->quantity)->toBe(7)
        ->and($product->inventory()->first()->reorder_level)->toBe(2);
});

test('posted audit and inventory movements reject direct mutation', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $otherOwner = User::factory()->administrator()->create();

    $this->actingAs($owner)->patch(route('administration.inventory.update', $product->inventory), [
        'quantity' => $product->inventory->quantity + 1,
        'reorder_level' => $product->inventory->reorder_level,
        'reason' => 'Count correction after audit',
    ])->assertSessionHasNoErrors();
    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $this->actingAs($otherOwner)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count verified in person',
    ])->assertSessionHasNoErrors();

    expect(fn () => DB::table('audit_logs')->where('subject_id', $requestId)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('inventory_movements')->where('reference_id', $requestId)->update(['kind' => 'altered']))->toThrow(QueryException::class);

    $sale = Sale::factory()->create();
    expect(fn () => DB::table('sales')->where('id', $sale->id)->delete())->toThrow(QueryException::class);
});
