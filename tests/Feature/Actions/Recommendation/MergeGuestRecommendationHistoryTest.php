<?php

use App\Actions\Recommendation\MergeGuestRecommendationHistory;
use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;

test('registration merge transfers current guest signals to the customer and consumes the profile', function () {
    $customer = User::factory()->customer()->create();
    $profile = GuestRecommendationProfile::factory()->create();
    $product = Product::factory()->create();
    $search = $profile->searches()->create([
        'query' => 'graphics card',
        'expires_at' => now()->addDays(90),
    ]);
    $view = $profile->productViews()->create([
        'product_id' => $product->id,
        'expires_at' => now()->addDays(90),
    ]);

    app(MergeGuestRecommendationHistory::class)($profile, $customer);

    expect($search->refresh()->user_id)->toBe($customer->id)
        ->and($search->guest_recommendation_profile_id)->toBeNull()
        ->and($view->refresh()->user_id)->toBe($customer->id)
        ->and($view->guest_recommendation_profile_id)->toBeNull();
    $this->assertModelMissing($profile);
});

test('registration merge discards activity disabled in customer preferences', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => false,
        'product_view_recommendations_enabled' => false,
    ]);
    $profile = GuestRecommendationProfile::factory()->create();
    $profile->searches()->create(['query' => 'monitor', 'expires_at' => now()->addDays(90)]);
    $profile->productViews()->create([
        'product_id' => Product::factory()->create()->id,
        'expires_at' => now()->addDays(90),
    ]);

    app(MergeGuestRecommendationHistory::class)($profile, $customer);

    $this->assertDatabaseCount('customer_searches', 0);
    $this->assertDatabaseCount('customer_product_views', 0);
    $this->assertModelMissing($profile);
});

test('registration merge removes near-duplicate activity and safely ignores a repeated attempt', function () {
    $customer = User::factory()->customer()->create();
    $profile = GuestRecommendationProfile::factory()->create();
    $product = Product::factory()->create();
    CustomerSearch::factory()->for($customer)->create([
        'query' => 'graphics car',
        'created_at' => now()->subMinutes(2),
    ]);
    CustomerProductView::factory()->for($customer)->for($product)->create([
        'created_at' => now()->subMinutes(2),
    ]);
    $profile->searches()->create([
        'query' => 'graphics card',
        'expires_at' => now()->addDays(90),
    ]);
    $profile->productViews()->create([
        'product_id' => $product->id,
        'expires_at' => now()->addDays(90),
    ]);

    $merge = app(MergeGuestRecommendationHistory::class);
    $merge($profile, $customer);
    $merge($profile, $customer);

    $this->assertDatabaseCount('customer_searches', 1);
    $this->assertDatabaseCount('customer_product_views', 1);
    $this->assertModelMissing($profile);
});

test('customer activity cannot be saved with zero or two owners', function () {
    $customer = User::factory()->customer()->create();
    $profile = GuestRecommendationProfile::factory()->create();
    $ownerlessSearch = new CustomerSearch;
    $ownerlessSearch->query = 'graphics card';
    $ownerlessSearch->expires_at = now()->addDays(90);
    $multiplyOwnedSearch = new CustomerSearch;
    $multiplyOwnedSearch->user_id = $customer->id;
    $multiplyOwnedSearch->guest_recommendation_profile_id = $profile->id;
    $multiplyOwnedSearch->query = 'graphics card';
    $multiplyOwnedSearch->expires_at = now()->addDays(90);

    expect(fn () => $ownerlessSearch->save())->toThrow(LogicException::class);
    expect(fn () => $multiplyOwnedSearch->save())->toThrow(LogicException::class);
});

test('registration merge discards expired signals and preserves longer duplicate dwell', function () {
    $customer = User::factory()->customer()->create();
    $profile = GuestRecommendationProfile::factory()->create();
    $product = Product::factory()->create();
    $current = CustomerProductView::factory()->for($customer)->for($product)->create(['dwell_seconds' => 5]);
    $profile->productViews()->create(['product_id' => $product->id, 'dwell_seconds' => 120, 'expires_at' => now()->addDay()]);
    $profile->searches()->create(['query' => 'expired', 'expires_at' => now()->subSecond()]);
    app(MergeGuestRecommendationHistory::class)($profile, $customer);
    expect($current->refresh()->dwell_seconds)->toBe(120);
    $this->assertDatabaseCount('customer_product_views', 1);
    $this->assertDatabaseCount('customer_searches', 0);
});

test('expired guest profiles cannot transfer still-unexpired signals', function () {
    $profile = GuestRecommendationProfile::factory()->create(['expires_at' => now()->subSecond()]);
    $profile->searches()->create(['query' => 'private search', 'expires_at' => now()->addDay()]);
    app(MergeGuestRecommendationHistory::class)($profile, User::factory()->customer()->create());
    $this->assertModelMissing($profile);
    $this->assertDatabaseCount('customer_searches', 0);
});
