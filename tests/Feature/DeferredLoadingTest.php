<?php

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Dashboard\DashboardService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard shells defer expensive work while keeping the greeting and navigation available', function (string $role, string $component) {
    $user = User::factory()->{$role}()->create();
    $this->mock(DashboardService::class)->shouldNotReceive($role === 'customer' ? 'customer' : 'administration');
    $this->mock(BuildRecommendationViewData::class)->shouldNotReceive('__invoke');

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->component($component)->missing('dashboard')->missing('recommendations'));
    expect($response->viewData('page')['deferredProps']['dashboard'])->toBe(['dashboard']);
    if ($role === 'customer') {
        $response->assertInertia(fn (Assert $page) => $page->where('is_first_visit', true)->where('recommendations_enabled', true));
        expect($response->viewData('page')['deferredProps']['recommendations'])
            ->toBe(['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope']);
    }
})->with([
    ['customer', 'Dashboard/Customer'],
    ['administrator', 'Dashboard/Administration'],
]);

test('catalog results remain available while recommendation ranking is deferred', function () {
    $product = Product::factory()->available()->create();
    $this->mock(BuildRecommendationViewData::class)->shouldNotReceive('__invoke');

    $response = $this->get(route('products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.id', $product->id)
        ->where('recommendations_enabled', true)
        ->missing('recommendations'));
    expect($response->viewData('page')['deferredProps']['recommendations'])
        ->toBe(['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope']);
});

test('paused personalization supplies an empty feed without scheduling a recommendation request', function (string $routeName) {
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);

    $response = $this->actingAs($customer)->get(route($routeName));

    $response->assertInertia(fn (Assert $page) => $page->where('recommendations_enabled', false)->where('recommendations', []));
    expect($response->viewData('page')['deferredProps']['recommendations'] ?? null)->toBeNull();
})->with(['dashboard', 'products.index']);

test('rescued dashboard failures can be retried without changing the first visit state', function () {
    $customer = User::factory()->customer()->create();
    $attempts = 0;
    $this->mock(DashboardService::class)->shouldReceive('customer')->twice()
        ->andReturnUsing(function (User $user) use (&$attempts, $customer): array {
            expect($user->id)->toBe($customer->id);
            if (++$attempts === 1) {
                throw new RuntimeException('Dashboard temporarily unavailable');
            }

            return ['summary' => ['cart_items' => 0, 'cart_units' => 0, 'active_orders' => 0, 'total_orders' => 0], 'latest_order' => null];
        });
    $initial = $this->actingAs($customer)->get(route('dashboard'));
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Customer',
        'X-Inertia-Partial-Data' => 'dashboard',
    ];

    $this->get(route('dashboard'), $headers)->assertOk()
        ->assertJsonMissingPath('props.dashboard')
        ->assertJsonMissingPath('props.is_first_visit')
        ->assertJsonPath('rescuedProps', ['dashboard']);
    $this->get(route('dashboard'), $headers)->assertOk()
        ->assertJsonPath('props.dashboard.summary.cart_items', 0)
        ->assertJsonMissingPath('props.is_first_visit')
        ->assertJsonMissingPath('props.recommendations');
    expect($customer->refresh()->has_visited_dashboard)->toBeTrue();
});

test('deferred dashboard requests still require an authenticated session', function () {
    $this->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Dashboard/Customer',
        'X-Inertia-Partial-Data' => 'dashboard',
    ])->assertRedirect(route('login'));
});

test('recommendation failures are rescued as a group and retries restore the feed', function () {
    $customer = User::factory()->customer()->create();
    Product::factory()->available()->create();
    $shouldFail = true;
    $this->mock(BuildRecommendationViewData::class)->shouldReceive('__invoke')
        ->andReturnUsing(function () use (&$shouldFail): array {
            if ($shouldFail) {
                throw new RuntimeException('Recommendations temporarily unavailable');
            }

            return ['recommendations' => [], 'is_personalized' => false, 'has_featured_fallback' => false, 'guest_recommendation_scope' => null];
        });
    $initial = $this->actingAs($customer)->get(route('products.index'));
    $keys = ['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope'];
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => implode(',', $keys),
    ];

    $this->get(route('products.index'), $headers)->assertOk()
        ->assertJsonMissingPath('props.recommendations')
        ->assertJsonPath('rescuedProps', $keys);
    $shouldFail = false;
    $this->get(route('products.index'), $headers)->assertOk()
        ->assertJsonPath('props.recommendations', [])
        ->assertJsonPath('props.is_personalized', false)
        ->assertJsonMissingPath('props.products');
});

test('recommendation-only requests do not paginate catalog products again', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create()->load(['category', 'inventory', 'tags']);
    $repository = $this->mock(ProductCatalogRepository::class);
    $repository->shouldReceive('paginate')->once()->andReturn(new LengthAwarePaginator(new EloquentCollection([$product]), 1, 12));
    $repository->shouldReceive('filterOptions')->once()->andReturn(['categories' => [], 'brands' => [], 'tags' => []]);
    $this->mock(BuildRecommendationViewData::class)->shouldReceive('__invoke')->once()->andReturn([
        'recommendations' => [], 'is_personalized' => false, 'has_featured_fallback' => false, 'guest_recommendation_scope' => null,
    ]);

    $this->actingAs($customer)->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.id', $product->id)
        ->loadDeferredProps('recommendations', fn (Assert $page) => $page->where('recommendations', [])));
});
