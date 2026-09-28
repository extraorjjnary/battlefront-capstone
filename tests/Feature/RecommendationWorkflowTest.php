<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\RecommendationIntendedUse;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Recommendation\RecommendationEngine;
use App\Services\Recommendation\RecommendedProduct;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<int>  $tagIds
 * @param  array<string, mixed>  $attributes
 */
function createWorkflowProduct(Category $category, array $tagIds, int $quantity, array $attributes = []): Product
{
    $product = Product::factory()->for($category)->create($attributes);
    $product->tags()->attach($tagIds);
    Inventory::factory()->for($product)->create([
        'quantity' => $quantity,
        'reorder_level' => 2,
    ]);

    return $product;
}

test('guests and customers can open the public recommendation form with current catalog options', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    createWorkflowProduct($category, [$tag->id], 3, ['brand' => 'AMD']);

    $guestResponse = $this->get(route('recommendations.index'));
    $customerResponse = $this->actingAs(User::factory()->create())->get(route('recommendations.index'));

    $guestResponse->assertInertia(fn (Assert $page) => $page
        ->component('Recommendations/Index')
        ->where('criteria', null)
        ->where('recommendations', null)
        ->has('intended_uses', count(RecommendationIntendedUse::cases()))
        ->where('intended_uses.3.value', 'networking_piso_wifi')
        ->where('intended_uses.3.label', 'Networking / Piso WiFi')
        ->where('filter_options.categories.0.name', 'Graphics Cards')
        ->where('filter_options.brands.0', 'AMD')
        ->where('filter_options.tags.0.name', 'Gaming'));
    $customerResponse->assertInertia(fn (Assert $page) => $page
        ->component('Recommendations/Index')
        ->where('recommendations', null));
});

test('guests and customers are authorized to use recommendations', function () {
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();

    expect(Gate::forUser(null)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('use-recommendations'))->toBeFalse();
});

test('customers can submit recommendation requirements', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $product = createWorkflowProduct($category, [$gaming->id], 3, ['price' => '500.00', 'brand' => null]);

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('recommendations.results', [
            'budget' => '500.00',
            'intended_use' => 'gaming',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recommendations/Index')
            ->where('criteria.budget', '500.00')
            ->where('criteria.intended_use', 'gaming')
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $product->id));
});

test('administrators cannot access the recommendation form or results', function (string $routeName, array $parameters) {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'form' => ['recommendations.index', []],
    'results' => ['recommendations.results', [
        'budget' => '500.00',
        'intended_use' => 'gaming',
    ]],
]);

test('valid requirements return ordered catalog products with prices stock and match evidence', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $preferred = Tag::factory()->create(['name' => 'Budget-Friendly']);
    $ordinary = createWorkflowProduct($category, [$gaming->id], 4, [
        'name' => 'Ordinary graphics card',
        'price' => '900.00',
    ]);
    $preferredProduct = createWorkflowProduct($category, [$gaming->id, $preferred->id], 1, [
        'name' => 'Preferred graphics card',
        'brand' => 'AMD',
        'description' => 'A current catalog description.',
        'price' => '1200.00',
        'discount_price' => '1000.00',
        'image_path' => 'products/preferred.webp',
    ]);

    $response = $this->get(route('recommendations.results', [
        'budget' => '1000.00',
        'intended_use' => 'gaming',
        'category_id' => $category->id,
        'tag_ids' => [$preferred->id],
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Recommendations/Index')
        ->where('criteria.budget', '1000.00')
        ->where('criteria.intended_use', 'gaming')
        ->where('criteria.category_id', $category->id)
        ->where('criteria.tag_ids', [$preferred->id])
        ->has('recommendations', 2)
        ->where('recommendations.0.product.id', $preferredProduct->id)
        ->where('recommendations.0.product.description', 'A current catalog description.')
        ->where('recommendations.0.product.brand', 'AMD')
        ->where('recommendations.0.product.price', '1200.00')
        ->where('recommendations.0.product.discount_price', '1000.00')
        ->where('recommendations.0.product.inventory.status', 'low_stock')
        ->where('recommendations.0.effective_price', '1000.00')
        ->where('recommendations.0.reasons.0.code', 'within_budget')
        ->where('recommendations.0.reasons.3.value', 'Graphics Cards')
        ->where('recommendations.0.reasons.4.value', 'Budget-Friendly')
        ->where('recommendations.1.product.id', $ordinary->id)
        ->where('recommendations.1.product.inventory.status', 'in_stock'));
});

test('omitted preferences keep a product without a brand eligible', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $product = createWorkflowProduct($category, [$gaming->id], 3, [
        'brand' => null,
        'price' => '500.00',
    ]);

    $response = $this->get(route('recommendations.results', [
        'budget' => '500.00',
        'intended_use' => 'gaming',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('criteria.preferred_brand', null)
        ->where('criteria.category_id', null)
        ->where('criteria.tag_ids', [])
        ->where('recommendations.0.product.id', $product->id)
        ->where('recommendations.0.product.brand', null));
});

test('an explicit preferred brand excludes unbranded products', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    createWorkflowProduct($category, [$gaming->id], 3, ['brand' => null, 'price' => '500.00']);
    $matching = createWorkflowProduct($category, [$gaming->id], 3, [
        'brand' => 'AMD',
        'price' => '500.00',
    ]);

    $response = $this->get(route('recommendations.results', [
        'budget' => '500.00',
        'intended_use' => 'gaming',
        'preferred_brand' => ' amd ',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('criteria.preferred_brand', 'amd')
        ->has('recommendations', 1)
        ->where('recommendations.0.product.id', $matching->id)
        ->where('recommendations.0.reasons.3.code', 'preferred_brand')
        ->where('recommendations.0.reasons.3.value', 'AMD'));
});

test('unavailable products produce a no-match result without exposing them', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    createWorkflowProduct($category, [$gaming->id], 0, ['price' => '500.00']);

    $response = $this->get(route('recommendations.results', [
        'budget' => '500.00',
        'intended_use' => 'gaming',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('criteria.intended_use', 'gaming')
        ->has('recommendations', 0));
});

test('the results route applies the existing recommendation input validation', function (array $criteria, array $errors) {
    $this->from(route('recommendations.index'))
        ->get(route('recommendations.results', $criteria))
        ->assertRedirect(route('recommendations.index'))
        ->assertSessionHasErrors($errors);
})->with([
    'missing required input' => [[], [
        'budget' => 'Enter a budget.',
        'intended_use' => 'Select an intended use.',
    ]],
    'nonpositive budget' => [[
        'budget' => '0',
        'intended_use' => 'gaming',
    ], ['budget' => 'Budget must be at least PHP 0.01.']],
    'invalid use' => [[
        'budget' => '500.00',
        'intended_use' => 'unknown',
    ], ['intended_use' => 'Select a valid intended use.']],
    'invalid category and tag' => [[
        'budget' => '500.00',
        'intended_use' => 'gaming',
        'category_id' => 999999,
        'tag_ids' => [999999],
    ], [
        'category_id' => 'Select an available category.',
        'tag_ids.0' => 'Select valid product tags.',
    ]],
]);

test('the results response presents the shared engine output without reranking it', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $first = createWorkflowProduct($category, [$gaming->id], 3, ['price' => '500.00']);
    $second = createWorkflowProduct($category, [$gaming->id], 3, ['price' => '400.00']);
    $products = app(ProductCatalogRepository::class)->recommendationInputs()->orderBy('id')->get();
    $engine = Mockery::mock(RecommendationEngine::class);
    $engine->shouldReceive('recommend')
        ->once()
        ->with(Mockery::on(fn (array $criteria): bool => $criteria['budget'] === '500.00'
            && $criteria['intended_use'] === RecommendationIntendedUse::Gaming
            && $criteria['preferred_brand'] === null
            && $criteria['tag_ids'] === []))
        ->andReturn(collect([
            new RecommendedProduct($products[0], '500.00', 1, 0, [
                ['code' => 'intended_use_tag', 'value' => 'Engine first'],
            ]),
            new RecommendedProduct($products[1], '400.00', 1, 0, [
                ['code' => 'intended_use_tag', 'value' => 'Engine second'],
            ]),
        ]));
    $this->app->instance(RecommendationEngine::class, $engine);

    $response = $this->get(route('recommendations.results', [
        'budget' => '500.00',
        'intended_use' => 'gaming',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('recommendations.0.product.id', $first->id)
        ->where('recommendations.0.reasons.0.value', 'Engine first')
        ->where('recommendations.1.product.id', $second->id)
        ->where('recommendations.1.reasons.0.value', 'Engine second'));
});

test('products without inventory produce an explicit empty result', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    Product::factory()->for($category)->create(['price' => '50.00', 'brand' => null]);

    $this->get(route('recommendations.results', [
        'budget' => '100.00',
        'intended_use' => 'networking_piso_wifi',
    ]))->assertInertia(fn (Assert $page) => $page
        ->component('Recommendations/Index')
        ->where('criteria.budget', '100.00')
        ->where('recommendations', []));
});

test('an empty catalog returns an empty result and filter options', function () {
    $this->get(route('recommendations.results', [
        'budget' => '100.00',
        'intended_use' => 'general_use',
    ]))->assertInertia(fn (Assert $page) => $page
        ->component('Recommendations/Index')
        ->where('criteria.intended_use', 'general_use')
        ->where('recommendations', [])
        ->where('filter_options.categories', [])
        ->where('filter_options.brands', [])
        ->where('filter_options.tags', []));
});

test('successful and no-match recommendations do not prompt AI or send external requests', function () {
    Inertia::disableSsr();
    $category = Category::factory()->create(['name' => 'Networking']);
    $product = createWorkflowProduct($category, [], 3, ['price' => '50.00', 'brand' => null]);
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    $this->get(route('recommendations.results', [
        'budget' => '100.00',
        'intended_use' => 'networking_piso_wifi',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('recommendations', 1)
        ->where('recommendations.0.product.id', $product->id));

    $this->get(route('recommendations.results', [
        'budget' => '0.01',
        'intended_use' => 'networking_piso_wifi',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('recommendations', []));

    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverQueued();
    Http::assertNothingSent();
});
