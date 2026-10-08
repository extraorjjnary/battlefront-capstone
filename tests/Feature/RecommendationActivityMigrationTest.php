<?php

use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('database activity writes reject missing and multiple owners', function (string $table, bool $bothOwners) {
    $attributes = ['user_id' => null, 'guest_recommendation_profile_id' => null, 'expires_at' => now()->addDay()];
    if ($table === 'customer_searches') {
        $attributes['query'] = 'keyboard';
    } else {
        $attributes['product_id'] = Product::factory()->create()->id;
    }
    if ($bothOwners) {
        $attributes['user_id'] = User::factory()->customer()->create()->id;
        $attributes['guest_recommendation_profile_id'] = GuestRecommendationProfile::factory()->create()->id;
    }
    expect(fn () => DB::table($table)->insert($attributes))->toThrow(QueryException::class);
})->with(['customer_searches', 'customer_product_views'])->with([false, true]);

test('database updates preserve exactly one owner and the correction refuses destructive rollback', function () {
    $search = CustomerSearch::factory()->create();
    expect(fn () => DB::table('customer_searches')->where('id', $search->id)->update(['user_id' => null]))->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_10_08_171612_enforce_recommendation_activity_ownership.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward-only');
    $this->assertModelExists($search);
});

test('forward ownership correction preserves valid customer and guest history', function () {
    $search = CustomerSearch::factory()->create(['query' => 'preserved keyboard']);
    $guest = GuestRecommendationProfile::factory()->create();
    $guestSearch = $guest->searches()->create(['query' => 'preserved monitor', 'expires_at' => now()->addDay()]);
    foreach (['customer_searches', 'customer_product_views'] as $table) {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $operation) {
                DB::unprepared('DROP TRIGGER '.$table.'_one_owner_'.$operation);
            }
        } else {
            DB::statement('ALTER TABLE '.$table.' DROP CHECK '.$table.'_one_owner');
        }
    }
    Schema::table('customer_product_views', function ($table) {
        $table->dropIndex('customer_views_user_created_idx');
        $table->dropIndex('customer_views_guest_created_idx');
    });
    $migration = require database_path('migrations/2026_10_08_171612_enforce_recommendation_activity_ownership.php');
    $migration->up();

    expect($search->refresh()->query)->toBe('preserved keyboard')
        ->and($guestSearch->refresh()->query)->toBe('preserved monitor')
        ->and($guestSearch->user_id)->toBeNull()
        ->and($guestSearch->guest_recommendation_profile_id)->toBe($guest->id);
});
