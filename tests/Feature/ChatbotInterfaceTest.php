<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;

test('customers can access the floating assistant on their dashboard', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.useCustomerCart', true));
});

test('the standalone chatbot page is no longer available', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get('/chatbot')
        ->assertMethodNotAllowed();
});

test('only customers receive the shared permission used by the floating assistant', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.useCustomerCart', false));

    auth()->logout();

    $this->get(route('branches.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.useCustomerCart', false));
});

test('customers receive only the chatbot response fields for a supported inquiry', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake([
        'The Sagay store is at the confirmed address.',
    ])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    Branch::factory()->create([
        'city' => 'Sagay City',
        'address' => 'Confirmed Sagay address',
    ]);

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertOk()
        ->assertExactJson([
            'message' => 'The Sagay store is at the confirmed address.',
            'source' => 'gemini',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('the chatbot rejects invalid messages without calling Gemini', function (array $payload) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'blank' => [['message' => '   ']],
    'non-string' => [['message' => ['Where is the store?']]],
    'too long' => [['message' => str_repeat('a', 1001)]],
]);

test('guests are redirected from chatbot submission', function () {
    $this->post(route('chatbot.store'), ['message' => 'Where is the store?'])
        ->assertRedirectToRoute('login');
});

test('administrators are forbidden from chatbot submission', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->postJson(route('chatbot.store'), ['message' => 'Show my order status'])
        ->assertForbidden();
});

test('unsupported inquiries reach the customer as a safe fallback', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Recommend the best laptop.'])
        ->assertOk()
        ->assertExactJson([
            'message' => 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('provider failures reach the customer as a safe fallback', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(
        fn () => throw new AiException('private provider error'),
    )->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    Branch::factory()->create(['city' => 'Sagay City']);

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertOk()
        ->assertExactJson([
            'message' => 'The chatbot is temporarily unavailable. Please try again later.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('another customers order never reaches Gemini through the chatbot endpoint', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->for($otherCustomer)->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), [
            'message' => "Track my order {$foreignOrder->reference}.",
        ])
        ->assertOk()
        ->assertExactJson([
            'message' => "I couldn't find a matching order in your account.",
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});
