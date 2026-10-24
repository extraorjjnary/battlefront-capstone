<?php

use App\Actions\Inventory\AdjustInventoryStock;
use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

test('reason based increases and decreases require independent approval and preserve quarantine and ledger entries', function (string $code, int $target, ?string $notes, int $delta, string $role) {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $reviewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
    $inventory = Product::factory()->available()->create()->inventory;
    $inventory->forceFill(['quantity' => 10, 'quarantined_quantity' => 3])->save();

    $this->actingAs($staff)->patch(route('administration.inventory.update', $inventory), [
        'quantity' => $target, 'reorder_level' => $inventory->reorder_level, 'reason_code' => $code, 'reason' => $notes,
    ])->assertSessionHasNoErrors();

    $requestId = DB::table('inventory_adjustment_requests')->value('id');
    $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 10, 'quarantined_quantity' => 3]);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment.requested', 'subject_id' => $requestId, 'actor_id' => $staff->id]);

    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Shelf count independently verified',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => $target, 'quarantined_quantity' => 3]);
    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $requestId, 'status' => 'approved', 'reason_code' => $code, 'reviewer_id' => $reviewer->id]);
    $this->assertDatabaseHas('inventory_movements', [
        'reference_type' => 'inventory_adjustment_request', 'reference_id' => $requestId,
        'sellable_before' => 10, 'sellable_delta' => $delta, 'sellable_after' => $target,
        'quarantined_before' => 3, 'quarantined_delta' => 0, 'quarantined_after' => 3,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment.approved', 'subject_id' => $requestId, 'actor_id' => $reviewer->id]);
})->with([
    'found' => ['found_stock', 12, null, 2],
    'loss' => ['theft_loss', 8, 'Two units missing after investigation', -2],
    'loss without notes' => ['theft_loss', 8, null, -2],
    'other without notes' => ['other', 12, null, 2],
    'count increase' => ['count_error', 12, null, 2],
    'count decrease' => ['count_error', 8, null, -2],
    'receiving increase' => ['receiving_error', 12, null, 2],
    'receiving decrease' => ['receiving_error', 8, null, -2],
    'unit increase' => ['unit_of_measure_error', 12, null, 2],
    'unit decrease' => ['unit_of_measure_error', 8, null, -2],
])->with(['manager', 'owner']);

test('approval enforces stock direction while accepting older requests without notes', function (string $code, int $target, string $notes, ?string $field) {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $inventory = Product::factory()->available()->create()->inventory;
    $inventory->forceFill(['quantity' => 10, 'quarantined_quantity' => 3])->save();
    $id = DB::table('inventory_adjustment_requests')->insertGetId([
        'product_id' => $inventory->product_id, 'requester_id' => $requester->id,
        'observed_quantity' => 10, 'target_quantity' => $target, 'reason_code' => $code, 'reason' => $notes,
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $beforeAudit = DB::table('audit_logs')->count();

    $response = $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $id), [
        'decision' => 'approve', 'reason' => 'Independent review of old request',
    ]);

    if ($field === null) {
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 8, 'quarantined_quantity' => 3]);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $id, 'reference_type' => 'inventory_adjustment_request', 'sellable_delta' => -2]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $id, 'action' => 'inventory.adjustment.approved']);

        return;
    }
    $response->assertSessionHasErrors($field);

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $id, 'status' => 'pending']);
    $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 10, 'quarantined_quantity' => 3]);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', $beforeAudit);

    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $id), [
        'decision' => 'reject', 'reason' => 'Withdraw and resubmit through the correct workflow',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $id, 'status' => 'rejected']);
})->with([
    'found decrease' => ['found_stock', 8, 'Shelf count checked', 'decision'],
    'loss increase' => ['theft_loss', 12, 'Shelf count checked', 'decision'],
    'loss without explanation' => ['theft_loss', 8, '', null],
    'loss with old default label' => ['theft_loss', 8, 'Theft or loss', null],
    'physical loss with old default label' => ['theft_loss', 8, 'Physical sellable stock count: Theft or loss', null],
    'damage' => ['damage', 8, 'Damaged units found', 'reason_code'],
]);

test('ordinary product editing cannot overwrite sellable or quarantined quantities', function () {
    $product = Product::factory()->available()->create();
    $inventory = $product->inventory;
    $before = $inventory->refresh()->getAttributes();

    $this->actingAs(User::factory()->administrator()->create())->put(route('administration.products.update', $product), [
        'edit_version' => $product->edit_version, 'product_code' => $product->product_code,
        'name' => $product->name, 'category_id' => $product->category_id,
        'price' => $product->price, 'discount_price' => $product->discount_price, 'is_featured' => false,
        'quantity' => 1000, 'quarantined_quantity' => 1000, 'inventory' => ['quantity' => 1000],
    ])->assertSessionHasNoErrors();

    expect($inventory->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
});

test('an automatic stock movement invalidates a stale manual adjustment without applying it', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;
    $requestId = app(RequestInventoryAdjustment::class)->execute($product, $requester, $before + 5, 'Physical stock count correction');
    app(AdjustInventoryStock::class)->decrease($product->inventory, 1);

    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count checked on site',
    ])->assertSessionHasErrors(['decision' => 'Stock changed after this request. Submit a new request.']);

    expect($product->inventory->refresh()->quantity)->toBe($before - 1);
    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $requestId, 'status' => 'pending']);
    $this->assertDatabaseMissing('inventory_movements', ['reference_type' => 'inventory_adjustment_request', 'reference_id' => $requestId]);
});

test('inventory adjustment requests can be searched by product, requester, or reason', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Search adjustment product']);
    $requestId = DB::table('inventory_adjustment_requests')->insertGetId([
        'product_id' => $product->id,
        'requester_id' => $administrator->id,
        'observed_quantity' => 1,
        'target_quantity' => 2,
        'status' => 'pending',
        'reason_code' => 'count_error', 'reason' => 'Search adjustment reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($administrator)->get(route('administration.inventory-adjustments.index', ['q' => 'Search adjustment product']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.q', 'Search adjustment product')
            ->where('requests.total', 1)
            ->where('requests.data.0.id', $requestId)
            ->where('requests.data.0.current_quantity', 0)
            ->where('requests.data.0.observed_quantity', 1)
            ->where('requests.data.0.target_quantity', 2)
            ->where('requests.data.0.replacement_id', null));
});

test('a physical stock count creates a separate approved adjustment request', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $inventory = $product->inventory;
    $observedQuantity = $inventory->quantity;
    $countedQuantity = $observedQuantity + 3;

    $this->actingAs($requester)->post(route('administration.inventory.count', $inventory), [
        'quantity' => $countedQuantity,
        'observed_quantity' => $observedQuantity,
        'reason_code' => 'count_error', 'reason' => 'Counted by staff at opening',
    ])->assertSessionHasNoErrors();

    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $this->assertDatabaseHas('inventory_adjustment_requests', [
        'id' => $requestId,
        'observed_quantity' => $observedQuantity,
        'target_quantity' => $countedQuantity,
        'reason_code' => 'count_error', 'reason' => 'Physical sellable stock count: Counted by staff at opening',
        'status' => 'pending',
    ]);
    expect($inventory->fresh()->quantity)->toBe($observedQuantity);

    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve',
        'reason_code' => 'count_error', 'reason' => 'Count reviewed against shelf record',
    ])->assertSessionHasNoErrors();

    expect($inventory->fresh()->quantity)->toBe($countedQuantity);
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $product->id,
        'reference_type' => 'inventory_adjustment_request',
        'reference_id' => $requestId,
        'sellable_before' => $observedQuantity,
        'sellable_after' => $countedQuantity,
    ]);
});

test('a physical count cannot be submitted against a stale inventory value', function () {
    $requester = User::factory()->administrator()->create();
    $inventory = Product::factory()->available()->create()->inventory;
    $observedQuantity = $inventory->quantity;
    $inventory->update(['quantity' => $observedQuantity + 1]);

    $this->actingAs($requester)->post(route('administration.inventory.count', $inventory), [
        'quantity' => $observedQuantity + 2,
        'observed_quantity' => $observedQuantity,
        'reason_code' => 'count_error', 'reason' => 'Shelf count from earlier page',
    ])->assertSessionHasErrors('quantity');

    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
});

test('physical stock count requests require a reason category', function () {
    $requester = User::factory()->administrator()->create();
    $inventory = Product::factory()->available()->create()->inventory;

    $this->actingAs($requester)->post(route('administration.inventory.count', $inventory), [
        'quantity' => $inventory->quantity + 1,
        'observed_quantity' => $inventory->quantity,
        'reason' => 'Counted by staff at opening',
    ])->assertSessionHasErrors('reason_code');

    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
});

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
        'reason_code' => 'count_error', 'reason' => 'Physical stock count correction',
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
        'reason_code' => 'count_error', 'reason' => 'Physical count correction',
    ])->assertSessionHasNoErrors();

    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $this->actingAs($owner)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'approve', 'reason' => 'Count checked on site',
    ])->assertSessionHasErrors('decision');

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $requestId, 'status' => 'pending']);
});

test('an authorized reviewer may reject a pending request after its requester is deactivated', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $before = $product->inventory->quantity;
    $this->actingAs($requester)->patch(route('administration.inventory.update', $product->inventory), [
        'quantity' => $before + 1,
        'reorder_level' => $product->inventory->reorder_level,
        'reason_code' => 'count_error', 'reason' => 'Physical count correction',
    ])->assertSessionHasNoErrors();
    $requestId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');
    $requester->update(['is_active' => false]);

    $this->actingAs($reviewer)->post(route('administration.inventory-adjustments.decide', $requestId), [
        'decision' => 'reject', 'reason' => 'Requester account is no longer active',
    ])->assertRedirect();

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $requestId, 'status' => 'rejected']);
    expect($product->inventory->fresh()->quantity)->toBe($before);
});

test('a requester can withdraw and replace a pending stock request without changing stock', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $initial = $product->inventory->quantity;
    $this->actingAs($staff)->patch(route('administration.inventory.update', $product->inventory), [
        'quantity' => $initial + 2, 'reorder_level' => $product->inventory->reorder_level, 'reason_code' => 'count_error', 'reason' => 'Physical count differs',
    ])->assertSessionHasNoErrors();
    $firstId = DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->value('id');

    $this->actingAs($other)->post(route('administration.inventory-adjustments.withdraw', $firstId), [
        'reason' => 'Request entered by mistake',
    ])->assertForbidden();
    $this->actingAs($staff)->post(route('administration.inventory-adjustments.withdraw', $firstId), [
        'reason' => 'Request entered by mistake',
    ])->assertSessionHasNoErrors();
    $this->actingAs($staff)->post(route('administration.inventory-adjustments.replace', $firstId), [
        'quantity' => $initial + 3, 'reason_code' => 'count_error', 'reason' => 'Corrected physical shelf count',
    ])->assertSessionHasNoErrors();
    $this->actingAs($staff)->post(route('administration.inventory-adjustments.replace', $firstId), [
        'quantity' => $initial + 4, 'reason_code' => 'count_error', 'reason' => 'Another correction attempt',
    ])->assertSessionHasErrors('replacement');

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $firstId, 'status' => 'withdrawn']);
    $this->assertDatabaseHas('inventory_adjustment_requests', ['replaces_request_id' => $firstId, 'target_quantity' => $initial + 3, 'status' => 'pending']);
    expect($product->inventory->fresh()->quantity)->toBe($initial);

    $replacementId = DB::table('inventory_adjustment_requests')->where('replaces_request_id', $firstId)->value('id');
    $this->get(route('administration.inventory-adjustments.index', ['case' => $firstId]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.id', $firstId)
            ->where('requests.data.0.replacement_id', $replacementId)
            ->where('requests.data.0.current_quantity', $initial));
});

test('opening stock stays unavailable until a second person approves it', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->create();

    $this->actingAs($requester)->post(route('administration.products.inventory.store', $product), [
        'quantity' => 7,
        'reorder_level' => 2,
        'reason_code' => 'receiving_error', 'reason' => 'Opening count on shelf',
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
        'reason_code' => 'count_error', 'reason' => 'Count correction after audit',
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
