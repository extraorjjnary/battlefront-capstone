<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\RecommendationIntendedUse;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Services\CatalogProductPresenter;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\Recommendation\RecommendationEngine;
use App\Services\Recommendation\RecommendedProduct;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $this->mock(ChatbotOrchestrationService::class)->shouldNotReceive('respond');
});

afterEach(function () {
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('recommendation routes enforce optional bearer authentication', function (string $method, string $path, string $access, int $status) {
    $customer = User::factory()->customer()->create();
    if ($access === 'customer') {
        $this->withToken($customer->createToken('Phone')->plainTextToken);
    } elseif ($access === 'administrator') {
        $admin = User::factory()->administrator()->create();
        $this->withToken($admin->createToken('Phone')->plainTextToken);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    } elseif ($access === 'expired') {
        $this->withToken($customer->createToken('Phone', ['*'], now()->subMinute())->plainTextToken);
    } elseif ($access === 'revoked') {
        $token = $customer->createToken('Phone');
        $token->accessToken->delete();
        $this->withToken($token->plainTextToken);
    } elseif ($access === 'malformed') {
        $this->withHeader('Authorization', 'Bearer');
    } elseif ($access === 'session') {
        $this->actingAs(User::factory()->administrator()->create());
    }

    $response = $this->{$method}($path, ['budget' => '100.00', 'intended_use' => 'gaming'])
        ->assertStatus($status);
    if ($status === 401) {
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    } elseif ($status === 403) {
        $response->assertJsonStructure(['message']);
    } else {
        $response->assertJsonStructure(['data']);
    }
})->with([
    ['post', '/api/v1/recommendations'],
    ['get', '/api/v1/recommendations/options'],
])->with([
    ['guest', 200], ['customer', 200], ['administrator', 403],
    ['invalid', 401], ['expired', 401], ['revoked', 401],
    ['malformed', 401], ['session', 200],
]);

test('omitted null and blank preferences retain unbranded eligible products', function (array $preferences) {
    $product = Product::factory()->create(['price' => '100.00', 'brand' => null]);
    $product->tags()->attach(Tag::factory()->create(['name' => 'Gaming']));
    Inventory::factory()->for($product)->create(['quantity' => 1, 'reorder_level' => 2]);

    $this->postJson('/api/v1/recommendations', [
        'budget' => '100.00', 'intended_use' => 'gaming', ...$preferences,
    ])->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.id', $product->id)
        ->assertJsonPath('data.0.product.brand', null)
        ->assertJsonPath('data.0.product.inventory', ['status' => 'low_stock'])
        ->assertJsonPath('data.0.effective_price', '100.00')
        ->assertJsonMissingPath('data.0.product.inventory.quantity');
})->with([
    [[]],
    [['preferred_brand' => null, 'category_id' => null, 'tag_ids' => null]],
    [['preferred_brand' => '  ', 'tag_ids' => []]],
]);

test('API results preserve shared engine ranking reasons pricing and web product presentation', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $preferred = Tag::factory()->create(['name' => 'Compact']);
    $products = collect();
    foreach ([
        ['price' => '150.00', 'discount_price' => '90.00'],
        ['price' => '90.00'],
        ['price' => '70.00'],
    ] as $attributes) {
        $product = Product::factory()->for($category)->create(['brand' => 'AMD', ...$attributes]);
        $product->tags()->attach([$gaming->id, $preferred->id]);
        Inventory::factory()->for($product)->create(['quantity' => 5, 'reorder_level' => 2]);
        $products->push($product);
    }
    $unbranded = Product::factory()->for($category)->create(['brand' => null, 'price' => '1.00']);
    $unbranded->tags()->attach($gaming);
    Inventory::factory()->for($unbranded)->create(['quantity' => 5]);
    $criteria = [
        'budget' => '100.00', 'intended_use' => RecommendationIntendedUse::Gaming,
        'preferred_brand' => 'amd', 'category_id' => $category->id, 'tag_ids' => [$preferred->id],
    ];
    $expected = app(RecommendationEngine::class)->recommend($criteria)
        ->map(function (RecommendedProduct $result): array {
            $product = app(CatalogProductPresenter::class)->present($result->product);
            unset($product['inventory']['quantity']);

            return ['product' => $product, 'effective_price' => $result->effectivePrice, 'reasons' => $result->reasons];
        })->all();
    $payload = [...$criteria, 'intended_use' => 'gaming'];

    $this->postJson('/api/v1/recommendations', $payload)->assertOk()->assertExactJson(['data' => $expected])
        ->assertJsonPath('data.0.product.id', $products[2]->id)
        ->assertJsonPath('data.1.product.id', $products[0]->id)
        ->assertJsonPath('data.2.product.id', $products[1]->id);
    $this->postJson('/api/v1/recommendations', $payload)->assertExactJson(['data' => $expected]);
});

test('recommendations call the shared engine with normalized validated criteria', function () {
    $this->mock(RecommendationEngine::class)->shouldReceive('recommend')->once()->with([
        'budget' => '100', 'intended_use' => RecommendationIntendedUse::Gaming,
        'preferred_brand' => null, 'category_id' => null, 'tag_ids' => [],
    ])->andReturn(collect());

    $this->postJson('/api/v1/recommendations', ['budget' => '100', 'intended_use' => 'gaming'])
        ->assertOk()->assertExactJson(['data' => []]);
});

test('recommendation results reflect live stock and category eligibility', function () {
    $product = Product::factory()->create(['price' => '10.00']);
    $product->tags()->attach(Tag::factory()->create(['name' => 'Gaming']));
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $payload = ['budget' => '100.00', 'intended_use' => 'gaming'];
    $this->postJson('/api/v1/recommendations', $payload)->assertJsonCount(1, 'data');

    $stock->update(['quantity' => 0]);
    $this->postJson('/api/v1/recommendations', $payload)->assertExactJson(['data' => []]);
    $stock->update(['quantity' => 5]);
    $product->category->update(['is_active' => false]);
    $this->postJson('/api/v1/recommendations', $payload)->assertExactJson(['data' => []]);
});

test('invalid recommendation criteria use shared validation errors', function (array $payload, array $fields) {
    $this->post('/api/v1/recommendations', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($fields)->assertJsonStructure(['message', 'errors']);
})->with([
    [[], ['budget', 'intended_use']],
    [['budget' => '0', 'intended_use' => 'gaming'], ['budget']],
    [['budget' => '10.001', 'intended_use' => 'gaming'], ['budget']],
    [['budget' => '10000000000', 'intended_use' => 'gaming'], ['budget']],
    [['budget' => 'abc', 'intended_use' => 'gaming'], ['budget']],
    [['budget' => '10', 'intended_use' => 'unknown'], ['intended_use']],
    [['budget' => '10', 'intended_use' => 'gaming', 'preferred_brand' => []], ['preferred_brand']],
    [['budget' => '10', 'intended_use' => 'gaming', 'category_id' => 999], ['category_id']],
    [['budget' => '10', 'intended_use' => 'gaming', 'tag_ids' => [999]], ['tag_ids.0']],
]);

test('inactive categories and duplicate tags are rejected', function () {
    $category = Category::factory()->create(['is_active' => false]);
    $tag = Tag::factory()->create();
    $this->postJson('/api/v1/recommendations', [
        'budget' => '10', 'intended_use' => 'gaming', 'category_id' => $category->id, 'tag_ids' => [$tag->id, $tag->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'tag_ids.0', 'tag_ids.1']);
});

test('options expose current catalog preferences and all shared intended uses', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($category)->create(['brand' => 'AMD']);
    $product->tags()->attach($tag);
    Product::factory()->for($category)->create(['brand' => null]);
    Product::factory()->inactive()->create(['brand' => 'Hidden brand']);
    Tag::factory()->create(['name' => 'Unused tag']);

    $this->get('/api/v1/recommendations/options')->assertOk()->assertExactJson(['data' => [
        'intended_uses' => array_map(fn (RecommendationIntendedUse $use): array => [
            'value' => $use->value, 'label' => $use->label(),
        ], RecommendationIntendedUse::cases()),
        'filter_options' => [
            'categories' => [['id' => $category->id, 'name' => 'Graphics Cards']],
            'brands' => ['AMD'],
            'tags' => [['id' => $tag->id, 'name' => 'Gaming']],
        ],
    ]]);
});

test('recommendations retain the shared API IP rate limit', function () {
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->get('/api/v1/recommendations/options')->assertOk();
    }
    $this->mock(RecommendationEngine::class)->shouldNotReceive('recommend');
    $this->postJson('/api/v1/recommendations', ['budget' => '100', 'intended_use' => 'gaming'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonStructure(['message']);
});
