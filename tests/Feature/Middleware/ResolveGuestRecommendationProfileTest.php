<?php

use App\Models\GuestRecommendationProfile;

test('an expired profile cookie is replaced with a fresh guest identity', function () {
    $token = 'expired-guest-recommendation-token';
    $expiredProfile = GuestRecommendationProfile::factory()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subSecond(),
    ]);

    $response = $this->withCookie('battlefront_recommendation_profile', $token)
        ->get(route('home'));

    $response->assertOk()->assertCookieNotExpired('battlefront_recommendation_profile');
    $this->assertModelMissing($expiredProfile);
    $this->assertDatabaseCount('guest_recommendation_profiles', 1);
    $this->assertDatabaseMissing('guest_recommendation_profiles', [
        'token_hash' => hash('sha256', $token),
    ]);
});

test('the scheduled activity cleanup deletes expired guest profiles', function () {
    $expiredProfile = GuestRecommendationProfile::factory()->create([
        'expires_at' => now()->subSecond(),
    ]);
    $activeProfile = GuestRecommendationProfile::factory()->create([
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan('app:prune-expired-customer-searches')->assertSuccessful();

    $this->assertModelMissing($expiredProfile);
    $this->assertModelExists($activeProfile);
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
