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
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'reject', 'reason' => 'Supplier request was mistaken'])
        ->assertSessionHasNoErrors();
    expect($product->inventory->fresh()->quantity)->toBe($before);
});

test('dispatch and acknowledgement keep their separate references', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($requester)->post(route('administration.suppliers.store'), ['name' => 'Reference Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Reference Supplier')->value('id');
    $this->actingAs($requester)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Wrong part supplied',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('supplier_returns')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'approve'])->assertSessionHasNoErrors();

    $this->actingAs($requester)->post(route('administration.supplier-returns.dispatch', $returnId), ['external_reference' => 'DISPATCH-1'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.acknowledge', $returnId), ['external_reference' => 'ACK-2'])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('supplier_returns', [
        'id' => $returnId, 'dispatch_reference' => 'DISPATCH-1', 'acknowledgement_reference' => 'ACK-2',
    ]);
});

test('a rejected linked supplier return needs another reviewer to resolve its quarantine', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $resolver = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($requester)->post(route('administration.suppliers.store'), ['name' => 'Linked Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Linked Supplier')->value('id');
    $this->actingAs($requester)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Damaged packaging found',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.damage-reports.decide', $damageId), [
        'disposition' => 'supplier_return', 'supplier_id' => $supplierId, 'reason' => 'Supplier must inspect the part',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('supplier_returns')->where('damage_report_id', $damageId)->value('id');
    $this->actingAs($requester)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'reject', 'reason' => 'Supplier declined the request'])->assertSessionHasNoErrors();

    $this->actingAs($requester)->post(route('administration.supplier-returns.follow-up', $returnId), [
        'decision' => 'write_off', 'reason' => 'Packaging cannot be sold',
    ])->assertSessionHasErrors('decision');
    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $returnId), [
        'decision' => 'write_off', 'reason' => 'Packaging cannot be sold',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('supplier_returns', ['id' => $returnId, 'status' => 'resolved']);
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'supplier_return', 'reference_id' => $returnId, 'kind' => 'supplier_return_write_off']);
});

test('retrying a rejected linked supplier return transfers the held units to a new request', function () {
    $reporter = User::factory()->administrator()->create();
    $approver = User::factory()->administrator()->create();
    $rejecter = User::factory()->administrator()->create();
    $resolver = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($reporter)->post(route('administration.suppliers.store'), ['name' => 'Retry Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Retry Supplier')->value('id');
    $this->actingAs($reporter)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Damaged part found',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $this->actingAs($approver)->post(route('administration.damage-reports.decide', $damageId), [
        'disposition' => 'supplier_return', 'supplier_id' => $supplierId, 'reason' => 'Supplier must inspect the part',
    ])->assertSessionHasNoErrors();
    $firstId = DB::table('supplier_returns')->where('damage_report_id', $damageId)->value('id');
    $this->actingAs($rejecter)->post(route('administration.supplier-returns.decide', $firstId), ['decision' => 'reject', 'reason' => 'Supplier declined the request'])->assertSessionHasNoErrors();

    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $firstId), [
        'decision' => 'retry', 'supplier_id' => $supplierId, 'reason' => 'Try another supplier contact',
    ])->assertSessionHasNoErrors();
    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $firstId), [
        'decision' => 'retry', 'supplier_id' => $supplierId, 'reason' => 'Try another supplier contact',
    ])->assertSessionHasErrors('decision');

    $this->assertDatabaseHas('supplier_returns', ['id' => $firstId, 'status' => 'superseded']);
    $this->assertDatabaseHas('supplier_returns', ['previous_supplier_return_id' => $firstId, 'status' => 'pending']);
    expect(DB::table('supplier_returns')->where('damage_report_id', $damageId)->count())->toBe(2);
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(1);
});

test('supplier acknowledgement can record one explicit outcome without moving stock or money', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($requester)->post(route('administration.suppliers.store'), ['name' => 'Outcome Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Outcome Supplier')->value('id');
    $this->actingAs($requester)->post(route('administration.supplier-returns.store'), [
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Incorrect part supplied',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('supplier_returns')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('administration.supplier-returns.dispatch', $returnId), ['external_reference' => 'SHIP-1'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.acknowledge', $returnId), ['external_reference' => 'ACK-1'])->assertSessionHasNoErrors();
    $quantity = $product->inventory->fresh()->quantity;
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);

    $this->actingAs($staff)->post(route('administration.supplier-returns.resolve', $returnId), [
        'outcome' => 'credit', 'external_reference' => 'INVALID-1', 'notes' => 'Staff cannot record the outcome',
    ])->assertForbidden();
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.resolve', $returnId), [
        'outcome' => 'credit', 'external_reference' => 'CREDIT-1', 'notes' => 'Supplier issued a credit note',
    ])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.supplier-returns.resolve', $returnId), [
        'outcome' => 'refund', 'external_reference' => 'REFUND-2', 'notes' => 'Duplicate outcome rejected',
    ])->assertSessionHasErrors('outcome');

    $this->assertDatabaseHas('supplier_return_resolutions', ['supplier_return_id' => $returnId, 'outcome' => 'credit', 'external_reference' => 'CREDIT-1']);
    expect($product->inventory->fresh()->quantity)->toBe($quantity);
    $this->assertDatabaseCount('supplier_return_resolutions', 1);
});
