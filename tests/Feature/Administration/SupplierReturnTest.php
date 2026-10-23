<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('supplier returns can be searched by product, supplier, requester, reason, or reference', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Search supplier return product']);
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Search supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $returnId = DB::table('supplier_returns')->insertGetId([
        'supplier_id' => $supplierId,
        'product_id' => $product->id,
        'requester_id' => $administrator->id,
        'quantity' => 1,
        'status' => 'pending',
        'external_reference' => 'SEARCH-REF',
        'reason' => 'Search supplier return reason',
        'created_at' => now()->subDays(40),
        'updated_at' => now(),
    ]);

    $this->actingAs($administrator)->get(route('administration.supplier-returns.index', ['q' => 'SEARCH-REF', 'overdue' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'SEARCH-REF')
            ->where('filters.overdue', true)
            ->where('returns.total', 1)
            ->where('returns.data.0.id', $returnId)
            ->where('returns.data.0.held_quantity', 1)
            ->where('returns.data.0.follow_up_overdue', true));
});

test('supplier returns search handoff references and omit resolved cases from overdue follow up', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Follow-up Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $oldId = DB::table('supplier_returns')->insertGetId([
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'requester_id' => $administrator->id,
        'quantity' => 1, 'status' => 'closed', 'dispatch_reference' => 'DISPATCH-LOOKUP',
        'reason' => 'Closed supplier return', 'created_at' => now()->subDays(45), 'updated_at' => now(),
    ]);
    DB::table('supplier_return_resolutions')->insert([
        'supplier_return_id' => $oldId, 'recorded_by' => $administrator->id, 'outcome' => 'credit',
        'external_reference' => 'CREDIT-LOOKUP', 'notes' => 'Supplier issued a credit', 'created_at' => now(),
    ]);
    DB::table('supplier_returns')->insert([
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'requester_id' => $administrator->id,
        'quantity' => 1, 'status' => 'dispatched', 'acknowledgement_reference' => 'ACK-LOOKUP',
        'reason' => 'Open supplier return', 'created_at' => now()->subDays(45), 'updated_at' => now(),
    ]);

    $this->actingAs($administrator)->get(route('administration.supplier-returns.index', ['overdue' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('returns.total', 1)
            ->where('returns.data.0.status', 'dispatched')
            ->where('returns.data.0.follow_up_overdue', true));
    $this->get(route('administration.supplier-returns.index', ['q' => 'DISPATCH-LOOKUP']))
        ->assertInertia(fn (Assert $page) => $page->where('returns.total', 1)
            ->where('returns.data.0.id', $oldId));
    $this->get(route('administration.supplier-returns.index', ['q' => 'ACK-LOOKUP']))
        ->assertInertia(fn (Assert $page) => $page->where('returns.total', 1)
            ->where('returns.data.0.status', 'dispatched'));
});

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
        'reason_code' => 'wrong_item', 'reason' => 'Wrong item supplied',
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
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'wrong_item', 'reason' => 'Incorrect delivery line',
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
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'wrong_item', 'reason' => 'Wrong part supplied',
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
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage', 'reason' => 'Damaged packaging found',
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
    $this->actingAs($requester)->post(route('administration.evidence.store', ['subjectType' => 'damage_report', 'subjectId' => $damageId]), [
        'files' => [UploadedFile::fake()->image('supplier-damage.jpg')],
    ])->assertSessionHasNoErrors();
    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $returnId), [
        'decision' => 'write_off', 'reason' => 'Packaging cannot be sold',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('supplier_returns', ['id' => $returnId, 'status' => 'resolved']);
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'supplier_return', 'reference_id' => $returnId, 'kind' => 'supplier_return_write_off']);
});

test('releasing linked supplier stock requires inspection confirmation', function () {
    $reporter = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $resolver = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($reporter)->post(route('administration.suppliers.store'), ['name' => 'Inspection Supplier'])->assertSessionHasNoErrors();
    $supplierId = DB::table('suppliers')->where('name', 'Inspection Supplier')->value('id');
    $this->actingAs($reporter)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'defect_discovered', 'reason' => 'Defect discovered during inspection',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $this->actingAs($reviewer)->post(route('administration.damage-reports.decide', $damageId), [
        'disposition' => 'supplier_return', 'supplier_id' => $supplierId, 'reason' => 'Return to supplier for assessment',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('supplier_returns')->where('damage_report_id', $damageId)->value('id');
    $this->actingAs($reporter)->post(route('administration.supplier-returns.decide', $returnId), ['decision' => 'reject', 'reason' => 'Supplier declined the request'])->assertSessionHasNoErrors();

    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $returnId), [
        'decision' => 'release', 'reason' => 'Inspection finds the unit is safe',
    ])->assertSessionHasErrors('inspection_confirmed');
    $this->actingAs($resolver)->post(route('administration.supplier-returns.follow-up', $returnId), [
        'decision' => 'release', 'reason' => 'Inspection finds the unit is safe', 'inspection_confirmed' => '1',
    ])->assertSessionHasNoErrors();

    expect($product->inventory->fresh()->quantity)->toBe($product->inventory->quantity)
        ->and($product->inventory->fresh()->quarantined_quantity)->toBe(0);
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
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'defect_discovered', 'reason' => 'Damaged part found',
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
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'wrong_item', 'reason' => 'Incorrect part supplied',
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
