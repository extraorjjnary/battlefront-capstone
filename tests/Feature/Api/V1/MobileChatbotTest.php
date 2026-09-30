<?php

use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('mobile chatbot requires a customer bearer token', function (string $access) {
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    if ($access === 'session') {
        $this->actingAs($customer);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    } elseif ($access === 'revoked') {
        $token = $customer->createToken('Phone');
        $token->accessToken->delete();
        $this->withToken($token->plainTextToken);
    } elseif ($access === 'expired') {
        $this->withToken($customer->createToken('Phone', ['*'], now()->subMinute())->plainTextToken);
    } elseif ($access === 'administrator') {
        $admin = User::factory()->administrator()->create();
        $this->withToken($admin->createToken('Phone')->plainTextToken);
    }

    $response = $this->post('/api/v1/chatbot', ['message' => 'Where is Sagay store?']);
    if ($access === 'administrator') {
        $response->assertForbidden()->assertJsonStructure(['message']);
    } else {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with(['missing', 'invalid', 'revoked', 'expired', 'session', 'administrator']);

test('mobile chatbot uses shared product store and FAQ context with the resource envelope', function (string $topic, string $message, string $fact) {
    ChatbotResponseAgent::fake(['Approved answer.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    if ($topic === 'product') {
        $product = Product::factory()->create(['name' => 'Aurelius Mouse', 'price' => '123.45']);
        Inventory::factory()->for($product)->create(['quantity' => 7]);
    } elseif ($topic === 'store') {
        Branch::factory()->create(['city' => 'Sagay City', 'address' => 'Confirmed Sagay address']);
    } else {
        ChatbotKnowledge::factory()->create([
            'category' => ChatbotCategory::Faq,
            'question_pattern' => 'What payment methods are accepted?',
            'response_template' => 'Approved payment options.',
        ]);
    }

    $response = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->post('/api/v1/chatbot', ['message' => $message])
        ->assertOk()->assertJsonCount(1)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.message', 'Approved answer.')
        ->assertJsonPath('data.source', 'gemini');
    expect($response->json('data.context_token'))->toBeString();
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, $fact));
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with([
    ['product', 'What is the price of Aurelius Mouse?', '123.45'],
    ['store', 'Where is the Sagay store?', 'Confirmed Sagay address'],
    ['faq', 'What payment methods are accepted?', 'Approved payment options.'],
]);

test('mobile chatbot validates input without invoking the provider', function (array $payload, string $field) {
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->post('/api/v1/chatbot', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertJsonStructure(['message', 'errors']);
    ChatbotResponseAgent::assertNeverPrompted();
})->with([
    [[], 'message'],
    [['message' => '   '], 'message'],
    [['message' => []], 'message'],
    [['message' => str_repeat('a', 1001)], 'message'],
    [['message' => 'Hello', 'context_token' => []], 'context_token'],
    [['message' => 'Hello', 'context_token' => str_repeat('a', 16385)], 'context_token'],
]);

test('mobile order answers send only owned minimal facts to Gemini', function () {
    ChatbotResponseAgent::fake(['Your order is pending.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create(['name' => 'Private Customer', 'email' => 'private@example.com']);
    $order = Order::factory()->for($customer)->paidWithGCash()->create([
        'recipient_name' => 'Private Recipient', 'contact_number' => '09171234567',
        'payment_proof_path' => 'payment-proofs/private.png',
    ]);

    $response = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/chatbot', ['message' => 'What is the status of '.$order->reference.'?'])
        ->assertOk()->assertJsonPath('data.message', 'Your order is pending.');

    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($order): bool {
        expect($prompt->prompt)->toContain($order->reference)
            ->not->toContain('Private Customer', 'private@example.com', 'Private Recipient', '09171234567', 'payment-proofs/', 'payment_method', 'user_id', 'total_amount');

        return true;
    });
    expect(array_keys($response->json('data')))->toBe(['message', 'source', 'context_token']);
    expect($response->getContent())->not->toContain('Private Recipient', 'payment-proofs/');
});

test('foreign and missing order questions return the same safe fallback without provider calls', function (bool $foreign) {
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create();
    $reference = $foreign ? $order->reference : 'BF-999999';

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/chatbot', ['message' => 'Status of '.$reference, 'user_id' => $order->user_id])
        ->assertOk()->assertJsonPath('data.source', 'fallback')
        ->assertJsonPath('data.message', "I couldn't find a matching order in your account.");
    ChatbotResponseAgent::assertNeverPrompted();
})->with([true, false]);

test('mobile provider failures retain shared safe fact-based fallback', function (string $failure) {
    ChatbotResponseAgent::fake(function () use ($failure): string {
        return match ($failure) {
            'empty' => '',
            'timeout' => throw new NetworkTimeoutException('private timeout', new Request('POST', 'https://example.test')),
            default => throw new AiException('private provider error'),
        };
    })->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City', 'address' => 'Confirmed Sagay address']);
    $customer = User::factory()->customer()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/chatbot', ['message' => 'Where is the Sagay store?'])
        ->assertOk()->assertJsonPath('data.source', 'fallback')
        ->assertJsonPath('data.message', 'Sagay City: Address: Confirmed Sagay address.')
        ->assertJsonCount(3, 'data');
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with(['provider', 'timeout', 'empty']);

test('mobile unsupported recommendation and sensitive inquiries bypass Gemini', function (string $message) {
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/chatbot', ['message' => $message])
        ->assertOk()->assertJsonPath('data.source', 'fallback')
        ->assertJsonPath('data.context_token', null);
    ChatbotResponseAgent::assertNeverPrompted();
})->with(['Tell me a joke.', 'Recommend a gaming PC for my budget.', 'My password is private-secret']);

test('mobile follow ups requery order payment facts and ownership', function () {
    ChatbotResponseAgent::fake(['Pending order.', 'Payment verified.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken);
    $context = $this->postJson('/api/v1/chatbot', ['message' => 'Status of '.$order->reference])
        ->assertOk()->json('data.context_token');
    $order->update(['payment_status' => PaymentStatus::Verified]);

    $this->postJson('/api/v1/chatbot', ['message' => 'Is my payment verified?', 'context_token' => $context])
        ->assertOk()->assertJsonPath('data.message', 'Payment verified.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"verified"'));
    $order->update(['user_id' => $other->id]);
    $this->postJson('/api/v1/chatbot', ['message' => 'Is my payment verified?', 'context_token' => $context])
        ->assertOk()->assertJsonPath('data.message', "I couldn't find a matching order in your account.");
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('mobile conversation context rejects expired tampered foreign account device and web scope', function (string $change) {
    ChatbotResponseAgent::fake(['Order pending.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken);
    if ($change === 'web') {
        $context = app(ChatbotConversation::class)->respond('Status of '.$order->reference, $customer, null, 'web-session')['context_token'];
    } else {
        $context = $this->postJson('/api/v1/chatbot', ['message' => 'Status of '.$order->reference])
            ->assertOk()->json('data.context_token');
    }
    if ($change === 'expired') {
        $this->travel(16)->minutes();
    } elseif ($change === 'tampered') {
        $context = 'tampered';
    } elseif ($change === 'account' || $change === 'device') {
        $owner = $change === 'account' ? User::factory()->customer()->create() : $customer;
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('Other phone')->plainTextToken);
    }

    $this->postJson('/api/v1/chatbot', ['message' => 'What is its order status?', 'context_token' => $context])
        ->assertOk()->assertJsonPath('data.source', 'fallback');
    ChatbotResponseAgent::assertPromptedTimes(1);
})->with(['expired', 'tampered', 'account', 'device', 'web']);

test('mobile chatbot shares the ten question allowance with web and other devices', function () {
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->postJson(route('chatbot.store'), ['message' => 'Tell me a joke.'])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($customer->createToken('Phone')->plainTextToken);
    for ($attempt = 0; $attempt < 9; $attempt++) {
        $this->postJson('/api/v1/chatbot', ['message' => 'Tell me a joke.'])->assertOk();
    }
    $this->app['auth']->forgetGuards();
    $this->withToken($customer->createToken('Other phone')->plainTextToken);
    $this->mock(ResolveStoreContext::class)->shouldNotReceive('execute');

    $this->postJson('/api/v1/chatbot', ['message' => 'Where is Sagay store?'])
        ->assertTooManyRequests()->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too many questions. Please wait a minute and try again.']);
    $this->app['auth']->forgetGuards();
    $other = User::factory()->customer()->create();
    $this->withToken($other->createToken('Phone')->plainTextToken)
        ->postJson('/api/v1/chatbot', ['message' => 'Tell me a joke.'])->assertOk();
    ChatbotResponseAgent::assertNeverPrompted();
});
