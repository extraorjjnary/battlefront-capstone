<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/test/administrator-only', fn () => response()->noContent())
        ->middleware(['web', 'auth', 'can:access-administration']);
});

test('guests are redirected to login', function () {
    $response = $this->get('/test/administrator-only');

    $response->assertRedirect(route('login'));
});

test('customers are forbidden from administrator routes', function () {
    $customer = User::factory()->customer()->create();

    $response = $this
        ->actingAs($customer)
        ->get('/test/administrator-only');

    $response->assertForbidden();
});

test('signed-in administrators can access their routes without two factor or a password change', function () {
    $administrator = User::factory()->administrator()->create(['must_change_password' => true]);

    expect($administrator->only(['role', 'administration_role_id', 'is_active', 'two_factor_confirmed_at']))->toBeArray();
    expect($administrator->administration_role_id)->not->toBeNull();
    expect($administrator->two_factor_confirmed_at)->toBeNull();
    expect($administrator->is_active)->toBeTrue();

    $response = $this
        ->actingAs($administrator)
        ->get('/test/administrator-only');

    $response->assertNoContent();
});

test('staff manager and owner reach their permitted features immediately after sign in', function () {
    $roles = [
        'staff' => 'administration.orders.index',
        'manager' => 'administration.reports.sales',
        'owner' => 'administration.team.index',
    ];

    foreach ($roles as $role => $route) {
        $user = User::factory()->administrator()->create([
            'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->get(route($route))->assertOk();
        $dashboard = $this->actingAs($user)->get(route('dashboard'));
        if ($role === 'staff') {
            $dashboard->assertRedirect(route('administration.orders.index'));
        } else {
            $dashboard->assertOk();
        }
    }
});
