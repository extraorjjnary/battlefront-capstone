<?php

use App\Mail\VerifyEmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('IT administrators can manage staff accounts and review audits without business access', function () {
    $this->withoutVite();

    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);

    expect($it->can('users.manage'))->toBeTrue()
        ->and($it->can('audit.view'))->toBeTrue()
        ->and($it->can('permissions.manage'))->toBeFalse()
        ->and($it->can('orders.view'))->toBeFalse()
        ->and($it->can('inventory.approve'))->toBeFalse()
        ->and($it->can('audit.export'))->toBeFalse();

    $this->actingAs($it)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/It')->missing('dashboard'));
    $this->actingAs($it)->get(route('administration.team.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('availableRoles', ['staff', 'manager'])
        ->where('canManagePermissions', false));
    $this->actingAs($it)->get(route('administration.reports.audit'))->assertOk();
    $this->actingAs($it)->get(route('administration.orders.index'))->assertForbidden();
    $this->actingAs($it)->get(route('administration.reports.audit.export'))->assertForbidden();
    $this->actingAs($it)->put(route('administration.team.permissions.update', 'staff'), ['permissions' => ['orders.view']])->assertForbidden();

    $this->actingAs($it)->patch(route('administration.team.update', $manager), [
        'role' => 'staff', 'is_active' => true,
    ])->assertSessionHasNoErrors();
    expect($manager->fresh()->administrationRoleCode())->toBe('staff');
});

test('IT administrators cannot create or alter owner and IT accounts', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    $owner = User::factory()->administrator()->create();

    $this->actingAs($it)->post(route('administration.team.store'), [
        'name' => 'Another Owner', 'email' => 'another-owner@example.test',
        'role' => 'owner', 'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'another-owner@example.test']);

    $this->actingAs($it)->patch(route('administration.team.update', $owner), [
        'role' => 'manager', 'is_active' => false,
    ])->assertForbidden();
    $this->actingAs($it)->patch(route('administration.team.update', $it), [
        'role' => 'manager', 'is_active' => true,
    ])->assertForbidden();
    expect($owner->fresh()->administrationRoleCode())->toBe('owner')
        ->and($it->fresh()->administrationRoleCode())->toBe('it');
});

test('an owner can create an IT account but cannot change its fixed permissions', function () {
    Mail::fake();
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->postJson(route('administration.team.email-verification.store'), ['email' => 'it-operator@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'it-operator@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $this->actingAs($owner)->post(route('administration.team.store'), [
        'name' => 'IT Operator', 'email' => 'it-operator@example.test',
        'role' => 'it', 'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertSessionHasNoErrors();

    $it = User::query()->where('email', 'it-operator@example.test')->firstOrFail();
    expect($it->administrationRoleCode())->toBe('it')
        ->and($it->must_change_password)->toBeFalse()
        ->and($it->can('access-administration'))->toBeTrue();

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'it'), [
        'permissions' => ['orders.view'],
    ])->assertSessionHasErrors('role');
    Mail::assertSentCount(1);
});

test('the rollout command assigns an existing administrator to IT and requires a password change', function () {
    $administrator = User::factory()->create(['role' => 'administrator']);

    $this->artisan('administration:assign-role', ['email' => $administrator->email, 'role' => 'it'])
        ->assertExitCode(0);

    expect($administrator->fresh()->administrationRoleCode())->toBe('it')
        ->and($administrator->fresh()->must_change_password)->toBeFalse();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'team.role.assigned_by_cli', 'subject_id' => $administrator->id,
    ]);
});
