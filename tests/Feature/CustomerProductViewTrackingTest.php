<?php

use App\Models\CustomerProductView;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('web product views are retained by default and duplicate refreshes are suppressed for 30 minutes', function () {
    $this->travelTo('2026-10-08 12:00:00');

    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();

    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();
    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();

    $this->assertDatabaseCount('customer_product_views', 1);
    $view = CustomerProductView::query()->sole();
    expect($view->product_id)->toBe($product->id)
        ->and($view->expires_at->toDateTimeString())
        ->toBe(now()->addDays(90)->toDateTimeString());
});

test('mobile product views use the authenticated bearer identity', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $product = Product::factory()->create();
    $token = $customer->createToken('test-device')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.products.show', $product))->assertOk();

    $this->assertDatabaseHas('customer_product_views', [
        'user_id' => $customer->id,
        'product_id' => $product->id,
    ]);
});

test('guest, opted-out customer, and administrator product views are not retained', function () {
    $customer = User::factory()->customer()->create(['product_view_recommendations_enabled' => false]);
    $administrator = User::factory()->administrator()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $product = Product::factory()->create();

    $this->get(route('products.show', $product))->assertOk();
    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();
    $this->actingAs($administrator)->get(route('products.show', $product))->assertOk();

    $this->assertDatabaseCount('customer_product_views', 0);
});

test('profile settings expose an independent product view recommendation consent', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('productViewRecommendationsEnabled', true));

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'product_view_recommendations_enabled' => '1',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->product_view_recommendations_enabled)->toBeTrue();
});

test('withdrawing product view consent deletes retained views immediately', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    CustomerProductView::factory()->count(2)->for($customer)->create();

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'product_view_recommendations_enabled' => '0',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->product_view_recommendations_enabled)->toBeFalse();
    $this->assertDatabaseCount('customer_product_views', 0);
});

test('the retention command removes expired product views and keeps unexpired ones', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $expired = CustomerProductView::factory()->create(['expires_at' => now()->subSecond()]);
    $current = CustomerProductView::factory()->create(['expires_at' => now()->addSecond()]);

    $this->artisan('app:prune-expired-customer-searches')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($current);
});
