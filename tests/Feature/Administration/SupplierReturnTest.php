<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('supplier return reserves stock, requires another reviewer, and dispatches once', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;
    $this->actingAs($requester)->post(route('administration.suppliers.store'), [
        'name' => 'Example Supplier',
    ])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Example Supplier')->value('id');

    $this->actingAs($requester)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplierId,
        'product_id' => $product->id,
        'quantity' => 1,
        'reason' => 'Wrong item supplied',
    ])->assertSessionHasNoErrors();

    $returnId = DB::table('supplier_returns')->where('product_id', $product->id)->value('id');
    expect($product->inventory->fresh()->quantity)->toBe($before - 1);
    $this->actingAs($requester)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'approve'])
        ->assertSessionHasErrors('decision');
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'approve'])
        ->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('administration.supplier-returns.dispatch', $returnId), [
        'external_reference' => 'SUP-ACK-1',
    ])->assertSessionHasNoErrors();
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->actingAs($requester)->post(route('administration.supplier-returns.dispatch', $returnId), [
        'external_reference' => 'SUP-ACK-2',
    ])->assertSessionHasErrors('dispatch');
    expect(DB::table('inventory_movements')->where('kind', 'supplier_return_dispatched')->where('reference_id', $returnId)->count())->toBe(1);
});

test('rejecting an ordinary supplier return releases its reserved units', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;
    $this->actingAs($requester)->post(route('administration.suppliers.store'), ['name' => 'Second Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Second Supplier')->value('id');
    $this->actingAs($requester)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Incorrect delivery line',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('supplier_returns')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'reject'])
        ->assertSessionHasNoErrors();
    expect($product->inventory->fresh()->quantity)->toBe($before);
});
