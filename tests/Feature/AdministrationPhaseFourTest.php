<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('product stale saves and missing price reasons leave the catalog unchanged', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->create(['price' => '100.00']);
    $historicalItem = OrderItem::factory()->for($product)->create(['price_at_time' => '100.00']);
    $payload = [
        ...validPackageMeasurements(),
        'edit_version' => 1, 'product_code' => $product->product_code, 'name' => $product->name,
        'category_id' => $product->category_id, 'brand' => $product->brand,
        'price' => '120.00', 'is_featured' => false,
    ];

    $this->actingAs($owner)->put(route('administration.products.update', $product), $payload)
        ->assertSessionHasErrors('price_change_reason');
    expect($product->fresh()->price)->toBe('100.00');

    $this->put(route('administration.products.update', $product), [...validPackageMeasurements(),
        ...$payload, 'price_change_reason' => 'Supplier price increased',
    ])->assertSessionHasNoErrors();
    $product->refresh();
    expect($product->price)->toBe('120.00')
        ->and($product->edit_version)->toBe(2);
    expect($historicalItem->fresh()->price_at_time)->toBe('100.00');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'product.updated', 'subject_id' => $product->id,
        'reason' => 'Supplier price increased',
    ]);

    $this->put(route('administration.products.update', $product), [...validPackageMeasurements(),
        ...$payload, 'price' => '130.00', 'price_change_reason' => 'Another change',
    ])->assertSessionHasErrors('edit_version');
    expect($product->fresh()->price)->toBe('120.00');
});

test('supplier and role permission forms reject stale versions', function () {
    $owner = User::factory()->administrator()->create(['name' => 'Aaron Owner']);
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Primary supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($owner)->patch(route('administration.suppliers.update', $supplierId), [
        'name' => 'Updated supplier', 'is_active' => true, 'edit_version' => 1,
    ])->assertSessionHasNoErrors();
    $this->patch(route('administration.suppliers.update', $supplierId), [
        'name' => 'Stale supplier', 'is_active' => false, 'edit_version' => 1,
    ])->assertSessionHasErrors('edit_version');
    expect(DB::table('suppliers')->where('id', $supplierId)->value('name'))->toBe('Updated supplier');

    $roleId = DB::table('administration_roles')->where('code', 'staff')->value('id');
    $this->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => ['orders.view'], 'edit_version' => 1,
    ])->assertSessionHasNoErrors();
    $this->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => [], 'edit_version' => 1,
    ])->assertSessionHasErrors('edit_version');
    expect(DB::table('administration_roles')->where('id', $roleId)->value('edit_version'))->toBe(2);
});

test('deactivated handlers keep their assigned orders visible for authorized reassignment', function () {
    $owner = User::factory()->administrator()->create(['name' => 'Aaron Owner']);
    $staff = User::factory()->administrator()->create([
        'name' => 'Zed Staff',
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['assigned_handler_id' => $staff->id]);

    $this->actingAs($owner)->get(route('administration.team.index'))
        ->assertInertia(fn (Assert $page) => $page->where('users.data.1.open_order_count', 1));
    $this->patch(route('administration.team.update', $staff), [
        'role' => 'staff', 'is_active' => false,
    ])->assertSessionHasNoErrors();
    $this->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.required_action', 'Reassign inactive handler'));
    $otherStaff = User::factory()->administrator()->create([
        'administration_role_id' => $staff->administration_role_id,
    ]);
    $this->actingAs($otherStaff)->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
    $this->actingAs($owner);
    $this->patch(route('administration.orders.handler.update', $order), ['handler_id' => $owner->id])
        ->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($owner->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order.handler.assigned', 'subject_id' => $order->id]);
});
