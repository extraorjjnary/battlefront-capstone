<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('the development users are verified with their expected roles when the default seeder is rerun', function () {
    $this->seed();

    $customer = User::query()->where('email', 'test@example.com')->firstOrFail();
    $administrator = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($customer->hasVerifiedEmail())->toBeTrue()
        ->and($customer->role)->toBe(UserRole::Customer)
        ->and($administrator->hasVerifiedEmail())->toBeTrue()
        ->and($administrator->role)->toBe(UserRole::Administrator);

    $customer->forceFill([
        'email_verified_at' => null,
        'role' => UserRole::Administrator,
    ])->save();
    $administrator->forceFill([
        'email_verified_at' => null,
        'role' => UserRole::Customer,
    ])->save();

    $this->seed();

    expect($customer->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($customer->role)->toBe(UserRole::Customer)
        ->and($administrator->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($administrator->role)->toBe(UserRole::Administrator);
});

test('default initialization does not load historical sales development fixtures', function (string $environment) {
    $disk = Storage::fake('local');
    $this->app->instance('env', $environment);

    $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseMissing('users', ['email' => 'historical-sales@example.test']);
    foreach (['products', 'categories', 'inventories', 'orders', 'order_items', 'sales'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(config('forecasting.operational_coverage'))->toBe([]);
    $disk->assertMissing(config('forecasting.development_manifest'));
})->with(['local', 'testing', 'production', 'staging']);
