<?php

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistActivity;
use App\Models\WishlistItem;
use Illuminate\Support\Str;

test('customers can save once remove and view only their own wishlist', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 1);
    $this->assertDatabaseCount('wishlist_activities', 1);
    $this->actingAs($otherCustomer)->get(route('wishlist.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Wishlist/Index')->where('wishlist.count', 0));
    $this->actingAs($customer)->get(route('wishlist.index'))->assertOk()->assertInertia(fn ($page) => $page->where('wishlist.count', 1));
    $this->actingAs($customer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('wishlist_items', 1);
    $this->actingAs($customer)->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 2);
    expect(WishlistActivity::query()->where('event_type', 'removed')->exists())->toBeTrue();
});

test('wishlist actions require an authenticated customer and reject unavailable products', function () {
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect(route('login'));

    $customer = User::factory()->customer()->create();
    $unavailable = Product::factory()->create(['is_active' => false]);
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $unavailable->id])->assertSessionHasErrors('product_id');
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('wishlist pagination clamps to the last page after the final item is removed', function () {
    $customer = User::factory()->customer()->create();

    foreach (range(1, 13) as $index) {
        $product = Product::factory()->available()->create();
        WishlistItem::query()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'save_cycle_id' => (string) Str::uuid(),
        ]);
    }

    $this->actingAs($customer)->get(route('wishlist.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.count', 13)
            ->where('wishlist.items.current_page', 2)
            ->where('wishlist.items.last_page', 2));

    $lastItem = WishlistItem::query()->where('user_id', $customer->id)->orderByDesc('id')->firstOrFail();
    $this->actingAs($customer)->delete(route('wishlist.destroy', $lastItem->product_id))->assertRedirect();

    $this->actingAs($customer)->get(route('wishlist.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.count', 12)
            ->where('wishlist.items.current_page', 1)
            ->where('wishlist.items.last_page', 1));
});

test('soft deleted products remain removable and are presented as unavailable', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);

    $product->delete();

    $this->actingAs($customer)->get(route('wishlist.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.items.data.0.is_available', false)
            ->where('wishlist.items.data.0.product', null));

    $this->actingAs($customer)->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('admin wishlist analytics require reporting permission', function () {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->get(route('administration.reports.wishlist'))->assertForbidden();
    $this->actingAs($customer)->get(route('administration.reports.wishlist.csv'))->assertForbidden();
});

test('authorized report users receive aggregated wishlist analytics and csv export', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);

    $this->actingAs($owner)->get(route('administration.reports.wishlist'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Administration/Reports/Wishlist')
            ->where('overview.entries', 1)
            ->where('overview.customers', 1)
            ->where('overview.products', 1)
            ->has('activity.daily')
            ->where('conversion.wishlist_to_cart', null));
    $this->actingAs($owner)->get(route('administration.reports.wishlist.csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('wishlist report applies product filters to totals activity rows and csv', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create(['discount_price' => '499.00']);
    $otherProduct = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $otherProduct->id]);
    $today = now(config('battlefront.timezone'))->toDateString();
    $filters = ['from' => $today, 'to' => $today, 'q' => $product->name];

    $this->actingAs($owner)->get(route('administration.reports.wishlist', $filters))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('overview.entries', 1)
            ->where('overview.customers', 1)
            ->where('overview.products', 1)
            ->where('activity.additions', 1)
            ->has('products.data', 1));

    $response = $this->actingAs($owner)->get(route('administration.reports.wishlist.csv', $filters))->assertOk();
    expect($response->streamedContent())->toContain($product->name, '499.00');
    expect($response->streamedContent())->not->toContain($otherProduct->name);
});

test('wishlist report rejects a date range longer than 365 days with a field error', function () {
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => now(config('battlefront.timezone'))->subDays(365)->toDateString(),
        'to' => now(config('battlefront.timezone'))->toDateString(),
    ]))->assertRedirect()->assertSessionHasErrors('to');

    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => now(config('battlefront.timezone'))->subDays(365)->toDateString(),
    ]))->assertRedirect()->assertSessionHasErrors('to');
});

test('wishlist report marks interest increasing only when both periods have a positive baseline', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $yesterday = now('UTC')->subDay()->startOfDay();
    WishlistItem::query()->create([
        'user_id' => $customer->id,
        'product_id' => $product->id,
        'save_cycle_id' => (string) Str::uuid(),
    ]);

    WishlistActivity::query()->create([
        'save_cycle_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'event_type' => 'added',
        'occurred_at' => $yesterday,
    ]);

    foreach (range(1, 3) as $addition) {
        WishlistActivity::query()->create([
            'save_cycle_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'event_type' => 'added',
            'occurred_at' => now('UTC'),
        ]);
    }

    $today = now(config('battlefront.timezone'))->toDateString();
    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => $today,
        'to' => $today,
        'minimum_saves' => 1,
        'minimum_net_additions' => 2,
        'minimum_increase_percent' => 20,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('increasing_interest.0.id', $product->id)
        ->where('increasing_interest.0.demand.increase_percent', 200));
});
