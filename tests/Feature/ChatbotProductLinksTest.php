<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

test('product presentation preserves identity and only qualifies requested simulated stock', function (string $routeName, string $prefix, bool $providerFails, bool $simulated, string $kind, string $topic) {
    Http::preventStrayRequests();
    config(['catalog-demo.enabled' => $simulated]);
    $name = $kind === 'prefixed demo' ? '[DEMO] Atlas Monitor X240' : 'Atlas Monitor X240';
    $demo = $kind !== 'ordinary';
    $product = Product::factory()->available()->create([
        'name' => $name, 'price' => '4200.00', 'discount_price' => null,
        'description' => 'A clear display',
        'image_path' => $kind === 'image demo' ? 'images/demo-products/monitor.webp' : null,
    ]);
    $product->inventory->update(['quantity' => 21]);
    $fact = match ($topic) {
        'stock' => $demo ? 'Store stock is unconfirmed.' : ($simulated ? 'Simulated stock: 21 units.' : 'Sagay stock: 21 available.'),
        'price' => ($demo ? 'Sample price: PHP ' : 'Listed price: PHP ').'4,200.00.',
        default => 'Product details: A clear display.',
    };
    ChatbotResponseAgent::fake($providerFails ? fn () => throw new AiException : [
        $name.'. '.($demo ? 'Demonstration product. ' : '').$fact,
    ])->preventStrayPrompts();
    $question = match ($topic) {
        'stock' => 'Is Atlas Monitor X240 available?',
        'price' => 'What is the price of Atlas Monitor X240?',
        default => 'What are the details of '.$name.'?',
    };

    $response = $this->postJson(route($routeName), ['message' => $question])->assertOk()
        ->assertJsonPath($prefix.'source', $providerFails ? 'fallback' : 'gemini')
        ->assertJsonPath($prefix.'product_links', [['id' => $product->id, 'name' => 'Atlas Monitor X240']]);

    expect($response->json($prefix.'message'))->toContain('Atlas Monitor X240', $fact)
        ->not->toContain('[DEMO]', 'Capstone demonstration', 'is_demo', 'is_demonstration', 'delivery_is_demo');
    if ($topic !== 'stock') {
        expect($response->json($prefix.'message'))->not->toContain('inventory', 'stock', 'simulated');
    }
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($simulated, $topic): bool {
        return ! str_contains($prompt->prompt, 'Capstone demonstration')
            && str_contains($prompt->prompt, '"inventory_notice"') === ($simulated && $topic === 'stock');
    });
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $name]);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 21]);
    Http::assertNothingSent();
})->with([
    'web' => ['chatbot.store', ''], 'mobile' => ['api.v1.chatbot.store', 'data.'],
])->with([false, true])->with([false, true])->with(['ordinary', 'prefixed demo', 'image demo'])->with(['stock', 'price', 'details']);

test('generated presentation removes technical demo flags without removing delivery qualifications', function () {
    ChatbotResponseAgent::fake([
        'Atlas Monitor X240. is_demo=true is_demonstration=true delivery_is_demo=true Delivery dates are estimates.',
    ])->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);

    $response = $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Atlas Monitor X240?'])->assertOk();

    expect($response->json('message'))->toContain('Delivery dates are estimates.')
        ->not->toContain('is_demo', 'is_demonstration', 'delivery_is_demo');
});

test('simulated availability remains identified when generated wording omits the qualification', function () {
    config(['catalog-demo.enabled' => true]);
    ChatbotResponseAgent::fake(['Atlas Monitor X240 has 21 units.'])->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);

    $response = $this->postJson(route('api.v1.chatbot.store'), ['message' => 'Is Atlas Monitor X240 available?'])->assertOk();

    expect($response->json('data.message'))->toContain('Stock quantities are simulated.');
});

test('demo clarification repetition and ordinal follow ups retain original product identities', function () {
    config(['catalog-demo.enabled' => true]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $products = Product::factory()->available()->count(2)->sequence(
        ['name' => '[DEMO] Atlas Monitor A100'], ['name' => '[DEMO] Atlas Monitor B100'],
    )->create();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('What is the price of Atlas Monitor?', null, null, 'demo-labels');
    $repeat = $chat->respond('yes', null, $first['context_token'], 'demo-labels');
    $chosen = $chat->respond('first', null, $repeat['context_token'], 'demo-labels');
    $details = $chat->respond('details', null, $chosen['context_token'], 'demo-labels');

    expect($first['message'])->toBe($repeat['message'])->not->toContain('[DEMO]', 'simulated');
    expect($chosen['product_links'])->toBe([['id' => $products[0]->id, 'name' => 'Atlas Monitor A100']]);
    expect($details['product_links'])->toBe($chosen['product_links']);
    expect($details['message'])->toContain('Demonstration product.')->not->toContain('stock', 'inventory', '[DEMO]');
});

test('simulated fallback distinguishes zero from missing stock', function (?int $quantity, string $expected) {
    config(['catalog-demo.enabled' => true]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $product = Product::factory()->create(['name' => 'Atlas Monitor X240']);
    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity]);
    }

    $this->postJson(route('api.v1.chatbot.store'), ['message' => 'Is Atlas Monitor X240 available?'])
        ->assertOk()->assertJsonPath('data.message', 'Atlas Monitor X240. '.$expected);
})->with([
    [0, 'Simulated stock: 0 units.'], [null, 'Simulated stock quantity is unavailable.'],
]);

test('web and mobile replies identify one available product without changing the answer', function (string $routeName, string $prefix, bool $authenticated, bool $providerFails) {
    Http::preventStrayRequests();
    $product = Product::factory()->available()->create(['name' => 'Atlas Monitor X240', 'price' => '4200.00']);
    ChatbotResponseAgent::fake($providerFails
        ? fn () => throw new AiException
        : ['Ang atlas monitor x240 ay PHP 4,200.00.'])->preventStrayPrompts();
    if ($authenticated) {
        $customer = User::factory()->customer()->create();
        if ($prefix === '') {
            $this->actingAs($customer);
        } else {
            $this->withToken($customer->createToken('Phone')->plainTextToken);
        }
    }

    $response = $this->postJson(route($routeName), ['message' => 'What is the price of Atlas Monitor X240?']);

    $response->assertOk()
        ->assertJsonPath($prefix.'message', $providerFails
            ? 'Atlas Monitor X240. Listed price: PHP 4,200.00.'
            : 'Ang atlas monitor x240 ay PHP 4,200.00.')
        ->assertJsonPath($prefix.'source', $providerFails ? 'fallback' : 'gemini')
        ->assertJsonPath($prefix.'product_links', [['id' => $product->id, 'name' => 'Atlas Monitor X240']]);
    expect($response->json($prefix.'context_token'))->toBeString();
    $this->get(route('products.show', $product->id))->assertOk();
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with([
    'web guest' => ['chatbot.store', '', false],
    'web customer' => ['chatbot.store', '', true],
    'mobile guest' => ['api.v1.chatbot.store', 'data.', false],
    'mobile customer' => ['api.v1.chatbot.store', 'data.', true],
])->with([false, true]);

test('product clarification replies identify each displayed product and retain links on repetition', function (string $routeName, string $prefix) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $products = Product::factory()->available()->count(4)->sequence(
        ['name' => 'Atlas Monitor A100'],
        ['name' => 'Atlas Monitor B100'],
        ['name' => 'Atlas Monitor C100'],
        ['name' => 'Atlas Monitor D100'],
    )->create();
    $expectedLinks = $products->take(3)->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])->all();

    $first = $this->postJson(route($routeName), ['message' => 'What is the price of Atlas Monitor?'])
        ->assertOk()->assertJsonPath($prefix.'product_links', $expectedLinks);
    if ($prefix === '') {
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }
    $second = $this->postJson(route($routeName), [
        'message' => 'yes', 'context_token' => $first->json($prefix.'context_token'),
    ])->assertOk()->assertJsonPath($prefix.'product_links', $expectedLinks);

    expect($first->json($prefix.'message'))->toContain('A100', 'B100', 'C100')->not->toContain('D100');
    expect($second->json($prefix.'message'))->toBe($first->json($prefix.'message'));
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'web' => ['chatbot.store', ''],
    'mobile' => ['api.v1.chatbot.store', 'data.'],
]);

test('unavailable or inactive products never receive navigation metadata', function (string $condition) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Atlas Monitor X240 is unavailable.'])->preventStrayPrompts();
    $factory = $condition === 'missing inventory' ? Product::factory() : Product::factory()->available();
    $product = $factory->create(['name' => 'Atlas Monitor X240']);
    match ($condition) {
        'zero stock' => $product->inventory->update(['quantity' => 0]),
        'inactive product' => $product->update(['is_active' => false]),
        'inactive category' => $product->category->update(['is_active' => false]),
        default => null,
    };

    $response = $this->postJson(route('chatbot.store'), ['message' => 'Is Atlas Monitor X240 available?']);

    $response->assertOk()->assertJsonMissingPath('product_links');
    if (in_array($condition, ['zero stock', 'missing inventory'], true)) {
        expect($response->json('message'))->toContain('Atlas Monitor X240');
        ChatbotResponseAgent::assertPromptedTimes(1);
    } else {
        expect($response->json('message'))->not->toContain('Atlas Monitor X240');
        ChatbotResponseAgent::assertNeverPrompted();
    }
    $this->get(route('products.show', $product->id))->assertNotFound();
    Http::assertNothingSent();
})->with(['zero stock', 'missing inventory', 'inactive product', 'inactive category']);

test('product links are rechecked after stock changes without changing stockless follow up answers', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $product = Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Is Atlas Monitor X240 available?', null, null, 'stock-change');
    $product->inventory->update(['quantity' => 0]);

    $second = $chat->respond('Is it available?', null, $first['context_token'], 'stock-change');

    expect($first['product_links'])->toBe([['id' => $product->id, 'name' => 'Atlas Monitor X240']]);
    expect($second)->not->toHaveKey('product_links');
    expect($second['message'])->toContain('Atlas Monitor X240', 'out of stock at Sagay');
    ChatbotResponseAgent::assertPromptedTimes(2);
    Http::assertNothingSent();
});

test('provider mentions outside the resolved context cannot create product links', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Helios Mouse Z10. [Unknown Product](javascript:alert(1))'])->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);
    Product::factory()->available()->create(['name' => 'Helios Mouse Z10']);

    $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Atlas Monitor X240?'])
        ->assertOk()->assertJsonMissingPath('product_links')
        ->assertJsonPath('message', 'Helios Mouse Z10. [Unknown Product](javascript:alert(1))');

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('products that become unavailable while generating a reply receive no links', function () {
    Http::preventStrayRequests();
    $product = Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);
    ChatbotResponseAgent::fake(function () use ($product): string {
        $product->inventory->update(['quantity' => 0]);

        return 'Atlas Monitor X240. Listed price: PHP 4,200.00.';
    })->preventStrayPrompts();

    $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Atlas Monitor X240?'])
        ->assertOk()->assertJsonMissingPath('product_links')
        ->assertJsonPath('message', 'Atlas Monitor X240. Listed price: PHP 4,200.00.');

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});
