<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing customer accounts', function () {
    $customer = User::factory()->customer()->create();

    $this->get(route('administration.customers.index'))
        ->assertRedirect(route('login'));
    $this->get(route('administration.customers.show', $customer))
        ->assertRedirect(route('login'));
});

test('customers are forbidden from viewing administrative customer records', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.customers.index'))
        ->assertForbidden();
    $this->actingAs($customer)
        ->get(route('administration.customers.show', $otherCustomer))
        ->assertForbidden();
});

test('administrators can view only customer accounts in stable name order', function () {
    $administrator = User::factory()->administrator()->create([
        'name' => 'Administrator Account',
    ]);
    User::factory()->customer()->create(['name' => 'Zoe Customer']);
    User::factory()->customer()->create(['name' => 'Alice Customer']);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.customers.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Customers/Index')
        ->has('customers.data', 2)
        ->has('customers.data.0', 5)
        ->where('customers.data.0.name', 'Alice Customer')
        ->where('customers.data.1.name', 'Zoe Customer')
        ->missing('customers.data.0.password')
        ->missing('customers.data.0.role')
        ->missing('customers.data.0.branch_id')
        ->missing('customers.data.0.remember_token')
        ->missing('customers.data.0.two_factor_secret')
        ->missing('customers.data.0.two_factor_recovery_codes'));
});

test('customer accounts are paginated', function () {
    $administrator = User::factory()->administrator()->create();
    User::factory()->customer()->count(26)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Customer %02d', 26 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.customers.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('customers.data', 25)
        ->where('customers.total', 26)
        ->where('customers.last_page', 2)
        ->where('customers.data.0.name', 'Customer 01')
        ->where('customers.data.24.name', 'Customer 25'));
});

test('administrators can view a minimal customer account record', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->unverified()->create([
        'name' => 'Jamie Customer',
        'email' => 'jamie@example.com',
        'created_at' => '2026-09-01 08:30:00',
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.customers.show', $customer));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Customers/Show')
        ->has('customer', 5)
        ->where('customer.id', $customer->id)
        ->where('customer.name', 'Jamie Customer')
        ->where('customer.email', 'jamie@example.com')
        ->where('customer.is_email_verified', false)
        ->where('customer.created_at', '2026-09-01T08:30:00+00:00')
        ->missing('customer.password')
        ->missing('customer.role')
        ->missing('customer.branch_id')
        ->missing('customer.updated_at')
        ->missing('customer.remember_token')
        ->missing('customer.two_factor_secret')
        ->missing('customer.two_factor_recovery_codes'));
});

test('administrator accounts cannot be opened as customer records', function () {
    $administrator = User::factory()->administrator()->create();
    $otherAdministrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('administration.customers.show', $otherAdministrator))
        ->assertForbidden();
});
