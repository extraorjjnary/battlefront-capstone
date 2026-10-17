<?php

use App\Actions\Order\RecordCompletedOrderSale;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('a staff member claims an order and the completed sale keeps that handler', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['handler_required' => true]);

    $this->actingAs($staff)->post(route('administration.orders.claim', $order))->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($staff->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order.handler.assigned', 'subject_id' => $order->id]);

    $order->update(['status' => OrderStatus::Completed]);
    app(RecordCompletedOrderSale::class)->execute($order->fresh());
    expect($order->sale->fresh()->handler_id)->toBe($staff->id);
});

test('manager sees team sales while staff sees only their own performance', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $order = Order::factory()->create();
    $order->sale()->create(['amount' => '240.00', 'sale_date' => today(), 'handler_id' => $staff->id]);

    $this->actingAs($manager)->get(route('administration.reports.staff'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/StaffPerformance')
        ->where('staff.0.id', $staff->id)->where('staff.0.orders_count', 1)->where('staff.0.gross_sales', '240'));
    $this->actingAs($staff)->get(route('administration.reports.staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/StaffPerformance')
            ->where('can_view_all', false)->has('staff', 1)->where('staff.0.id', $staff->id));
    $this->actingAs($staff)->get(route('administration.reports.audit'))->assertForbidden();
    $this->actingAs($manager)->get(route('administration.reports.audit'))->assertOk();
    $this->actingAs($manager)->get(route('administration.reports.audit.export'))->assertForbidden();
});

test('order handlers need order permission but do not need two factor enrollment', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    $order = Order::factory()->create(['handler_required' => true]);

    expect($staff->two_factor_confirmed_at)->toBeNull();
    $this->actingAs($manager)->get(route('administration.orders.show', $order))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('handler_options', fn ($options): bool => collect($options)->pluck('id')->contains($staff->id)
            && ! collect($options)->pluck('id')->contains($it->id)));
    $this->actingAs($manager)->patch(route('administration.orders.handler.update', $order), [
        'handler_id' => $it->id,
    ])->assertSessionHasErrors('handler');

    $this->actingAs($manager)->patch(route('administration.orders.handler.update', $order), [
        'handler_id' => $staff->id,
    ])->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($staff->id);
});
