<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\CustomerSearch;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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

    $response = $this->{$method}($path, [])
        ->assertStatus($status);
    if ($status === 401) {
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    } elseif ($status === 403) {
        $response->assertJsonStructure(['message']);
    } else {
        $response->assertJsonStructure(['data']);
    }
})->with([
    ['get', '/api/v1/recommendations'],
])->with([
    ['guest', 200], ['customer', 200], ['administrator', 403],
    ['invalid', 401], ['expired', 401], ['revoked', 401],
    ['malformed', 401], ['session', 200],
]);

test('public recommendation feed uses one shared product and reason shape for guests and customers', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $popularProduct = Product::factory()->for($category)->create(['name' => 'Popular graphics card']);
    Inventory::factory()->for($popularProduct)->create(['quantity' => 5]);
    $customer = User::factory()->customer()->create(['search_recommendations_enabled' => true]);
    $searchMatch = Product::factory()->for($category)->create(['name' => 'RTX 5070 graphics card']);
    Inventory::factory()->for($searchMatch)->create(['quantity' => 5]);
    CustomerSearch::factory()->for($customer)->create([
        'query' => 'rtx 5070',
        'expires_at' => now()->addDays(90),
    ]);
    $order = Order::factory()->for(User::factory()->customer()->create())->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($popularProduct)->create();

    $guestResponse = $this->getJson('/api/v1/recommendations')->assertOk();
    $guestResponse->assertJsonPath('data.0.product.id', $popularProduct->id)
        ->assertJsonPath('data.0.reasons.0.code', 'popular_with_customers');

    $customerResponse = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->getJson('/api/v1/recommendations')->assertOk();
    $customerResponse->assertJsonPath('data.0.product.id', $searchMatch->id)
        ->assertJsonPath('data.0.reasons.0.code', 'matched_recent_searches')
        ->assertJsonStructure(['data' => [[
            'product' => ['id', 'name', 'price', 'inventory'],
            'effective_price',
            'reasons' => [['code', 'value']],
        ]]]);
});

test('retired recommendation endpoints have no registered routes', function () {
    $this->getJson('/api/v1/recommendations/options')->assertNotFound();
    $this->postJson('/api/v1/recommendations')->assertStatus(405);
    expect(Route::has('api.v1.recommendations.options'))->toBeFalse()
        ->and(Route::has('api.v1.recommendations.results'))->toBeFalse();
});

test('behavioral feeds retain the shared API IP rate limit', function () {
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson('/api/v1/recommendations')->assertOk();
    }
    $this->getJson('/api/v1/recommendations')->assertTooManyRequests()->assertHeader('Retry-After');
});
