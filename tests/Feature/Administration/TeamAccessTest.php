<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\TeamAccessChanged;
use App\Services\Notifications\NotificationHistory;
use Illuminate\Support\Facades\DB;

test('team access notifications contain the audit event for owners', function () {
    $owner = User::factory()->administrator()->create();
    $notification = new TeamAccessChanged('role.permissions.updated', $owner->name, 7);
    $payload = $notification->toArray($owner);
    $owner->notify($notification);

    expect($payload['audience'])->toBe('administrator')
        ->and($payload['event'])->toBe('role.permissions.updated')
        ->and($payload['body'])->toContain('record #7')
        ->and(app(NotificationHistory::class)->summary($owner)['unread_count'])->toBe(1);
});

test('existing administrator roles are mapped explicitly and the mapping is audited', function () {
    $administrator = User::factory()->create(['role' => UserRole::Administrator->value]);
    expect($administrator->administration_role_id)->toBeNull();

    $this->artisan('administration:assign-role', ['email' => $administrator->email, 'role' => 'manager'])
        ->assertExitCode(0);

    expect($administrator->fresh()->administrationRoleCode())->toBe('manager');
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.role.assigned_by_cli', 'subject_id' => $administrator->id]);
});

test('owner can create a team account with immediate role-based access', function () {
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->post(route('administration.team.store'), [
        'name' => 'Stock Clerk',
        'email' => 'stock.clerk@example.test',
        'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertSessionHasNoErrors();

    $staff = User::query()->where('email', 'stock.clerk@example.test')->firstOrFail();
    expect($staff->administrationRoleCode())->toBe('staff')
        ->and($staff->must_change_password)->toBeFalse()
        ->and($staff->can('access-administration'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.created', 'subject_id' => $staff->id]);
});

test('manager cannot edit permissions and owner cannot deactivate the last owner', function () {
    $owner = User::factory()->administrator()->create();
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);

    $this->actingAs($manager)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => ['orders.view'],
    ])->assertForbidden();

    $this->actingAs($owner)->patch(route('administration.team.update', $owner), [
        'role' => 'owner', 'is_active' => false,
    ])->assertSessionHasErrors('user');

    expect($owner->fresh()->is_active)->toBeTrue();
});

test('owner permission changes take effect on the next request', function () {
    $owner = User::factory()->administrator()->create();
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $existing = DB::table('administration_role_permissions as grants')
        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
        ->where('grants.administration_role_id', $manager->administration_role_id)
        ->where('permissions.code', '!=', 'reports.view')
        ->pluck('permissions.code')->all();

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'manager'), [
        'permissions' => $existing,
        'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $this->actingAs($manager)->get(route('administration.reports.sales'))->assertForbidden();
    $this->assertDatabaseHas('audit_logs', ['action' => 'role.permissions.updated']);
});

test('owner can remove every permission from a role', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), ['edit_version' => 1])
        ->assertSessionHasNoErrors();

    $this->actingAs($staff)->get(route('administration.orders.index'))->assertForbidden();
});

test('inactive administrator sessions are rejected', function () {
    $owner = User::factory()->administrator()->create();
    $otherOwner = User::factory()->administrator()->create();

    $this->actingAs($otherOwner)->patch(route('administration.team.update', $owner), [
        'role' => 'owner', 'is_active' => false,
    ])->assertSessionHasNoErrors();

    $inactiveResponse = $this->actingAs($owner->fresh())->get(route('administration.orders.index'));
    expect($inactiveResponse->status())->toBe(403);
});

test('administrators without report access land on a page their role can open', function () {
    $roleId = DB::table('administration_roles')->where('code', 'manager')->value('id');
    $administrator = User::factory()->administrator()->create(['administration_role_id' => $roleId]);
    DB::table('administration_role_permissions')->where('administration_role_id', $roleId)->delete();
    $permissionId = DB::table('administration_permissions')->where('code', 'inventory.view')->value('id');
    DB::table('administration_role_permissions')->insert([
        'administration_role_id' => $roleId,
        'administration_permission_id' => $permissionId,
    ]);

    $this->actingAs($administrator)->get(route('dashboard'))
        ->assertRedirect(route('administration.inventory.index'));
});
