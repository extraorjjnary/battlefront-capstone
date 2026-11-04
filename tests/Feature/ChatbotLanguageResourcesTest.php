<?php

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

$cases = collect(json_decode(file_get_contents(__DIR__.'/../Fixtures/chatbot-multilingual.json'), true, flags: JSON_THROW_ON_ERROR)['cases'])
    ->keyBy('id')->map(fn (array $case): array => [$case]);

test('curated product questions use current catalog facts without classification', function (array $case) {
    $product = Product::factory()->available()->create([
        'name' => 'NVIDIA GeForce RTX 4060', 'price' => '19876.00', 'discount_price' => null,
        'description' => 'Verified graphics specification.',
    ]);
    $product->inventory->update(['quantity' => 7]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond($case['question']);

    expect($result['category']->value)->toBe($case['expected_category']);
    expect($result['message'])->toContain($product->name);
    $expected = match ($case['expected_intent']) {
        'product_price' => 'PHP 19,876.00',
        'product_stock' => 'Sagay stock: 7 available.',
        'product_details' => 'Verified graphics specification.',
    };
    expect($result['message'])->toContain($expected);
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with($cases->filter(fn (array $row): bool => str_starts_with($row[0]['expected_handling'], 'live_product'))->all());

test('curated store questions retrieve published branch facts', function (array $case) {
    Branch::factory()->create([
        'city' => 'Sagay City', 'address' => 'Verified Sagay address',
        'operating_hours' => '9 AM to 5 PM', 'contact_number' => '09123456789',
    ]);
    Branch::factory()->create(['city' => 'Hidden City', 'address' => 'Private address', 'is_published' => false]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond($case['question']);

    expect($result['category']->value)->toBe($case['expected_category']);
    $expected = match ($case['expected_intent']) {
        'store_location' => 'Verified Sagay address',
        'store_hours' => '9 AM to 5 PM',
        'store_contact' => '09123456789',
    };
    expect($result['message'])->toContain($expected)->not->toContain('Private address');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertPromptedTimes(1);
})->with($cases->filter(fn (array $row): bool => str_starts_with($row[0]['expected_handling'], 'live_store'))->all());

test('curated policy questions retain authoritative topics and English guidance', function (array $case) {
    $classification = app(ClassifyChatbotInquiry::class)->execute($case['question']);

    $result = app(ChatbotOrchestrationService::class)->respond($case['question']);

    expect($classification['rule_topic'])->toBe($case['expected_intent']);
    expect($result['category']->value)->toBe($case['expected_category']);
    expect($result['source'])->toBe('fallback');
    expect($result['message'])->not->toBeEmpty()->not->toContain('currently unavailable');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
})->with($cases->filter(fn (array $row): bool => $row[0]['expected_handling'] === 'authoritative_guidance')->all());

test('curated order questions require ownership and retrieve current status', function (array $case) {
    $owner = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $order = Order::factory()->for($owner)->create(['id' => 42, 'status' => 'processing']);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotOrchestrationService::class);

    $result = $chat->respond($case['question'], $owner);

    expect($result['category']->value)->toBe($case['expected_category']);
    expect($result['message'])->toContain($order->reference, 'Processing');
    $order->update(['status' => 'completed']);
    expect($chat->respond($case['question'], $owner)['message'])->toContain('Completed')->not->toContain('Processing');
    expect($chat->respond($case['question'], $other)['message'])->toContain("couldn't find a matching order");
    expect($chat->respond($case['question'])['message'])->toContain('sign in');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertPromptedTimes(2);
})->with($cases->filter(fn (array $row): bool => $row[0]['expected_handling'] === 'owned_live_order')->all());

test('curated questions without product identification ask for details', function (array $case) {
    Product::factory()->available()->create(['name' => 'NVIDIA GeForce RTX 4060']);

    $result = app(ChatbotOrchestrationService::class)->respond($case['question']);

    expect($result['category']->value)->toBe($case['expected_category']);
    expect($result['message'])->toContain('provide a product name or model')->not->toContain('RTX 4060');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
})->with($cases->filter(fn (array $row): bool => $row[0]['expected_handling'] === 'ask_product')->all());

test('curated independent topics ask which topic to handle first', function (array $case) {
    $routing = app(RouteChatbotQuery::class)->execute($case['question']);

    $result = app(ChatbotConversation::class)->respond($case['question'], null, null, 'evaluation');

    expect($routing['choices'])->toHaveKeys(['product', 'store']);
    expect($result['message'])->toContain('Which would you like to ask about first');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
})->with($cases->filter(fn (array $row): bool => $row[0]['expected_handling'] === 'ask_topic')->all());

test('curated unclear expressions ask for details without inventing facts', function (array $case) {
    $result = app(ChatbotConversation::class)->respond($case['question'], null, null, 'evaluation');

    expect($result['source'])->toBe('fallback');
    expect(strtolower($result['message']))->toContain('which product')->not->toContain('php', 'available.');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
})->with($cases->filter(fn (array $row): bool => $row[0]['expected_handling'] === 'ask_details')->all());

test('verified temporal vocabulary selects a language without establishing a stock intent', function (string $word, string $language) {
    $matcher = new ChatbotLanguage;
    expect($matcher->select('Stock of RTX 4060 '.$word.'?'))->toBe($language);
    expect($matcher->select('Price?'))->toBe('en');
    expect($matcher->detect('Pila?'))->toBeNull();
    expect($matcher->detect('Stock subong karon?'))->toBeNull();
    expect($matcher->select('Price of '.$word.'-M2?'))->toBe('en');
    expect($matcher->canonical('SUBONG-M2 karon_code ngayon2 BF-000042 PHP 123.45'))->toBe('SUBONG-M2 karon_code ngayon2 BF-000042 PHP 123.45');
})->with([['subong', 'hil'], ['karon', 'ceb'], ['ngayon', 'tl']]);

test('temporal follow ups requery stock and preserve explicit language through English fallback', function (string $word) {
    $product = Product::factory()->available()->create(['name' => 'NVIDIA GeForce RTX 4060']);
    $product->inventory->update(['quantity' => 4]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RTX 4060 stock? Reply in Hiligaynon.', null, null, 'temporal');
    $product->inventory->update(['quantity' => 0]);

    $next = $chat->respond('Stock '.$word.'?', null, $first['context_token'], 'temporal');

    expect($next['message'])->toContain('out of stock at Sagay')->not->toContain('4 available');
    expect(app(ChatbotConversationToken::class)->decode($next['context_token'], 'temporal:guest')['language'])->toBe('hil');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Reply in Hiligaynon'));
    ChatbotResponseAgent::assertPromptedTimes(2);
    ChatbotIntentAgent::assertNeverPrompted();
})->with(['subong', 'karon', 'ngayon']);

test('regional price follow ups retain explicit choices and refresh prices', function (string $question, string $preference, string $code) {
    $product = Product::factory()->available()->create(['name' => 'NVIDIA GeForce RTX 4060', 'price' => '19000.00', 'discount_price' => null]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RTX 4060 price? Reply in Hiligaynon.', null, null, 'price');
    $switch = $chat->respond('Reply in '.$preference.'.', null, $first['context_token'], 'price');
    $shortcut = $chat->respond('What payment methods can I use?', null, $switch['context_token'], 'price');
    $resumed = $chat->respond('Price of the earlier product?', null, $shortcut['context_token'], 'price');
    $product->update(['price' => '18000.00']);

    $next = $chat->respond($question, null, $resumed['context_token'], 'price');

    expect($next['message'])->toContain('PHP 18,000.00')->not->toContain('PHP 19,000.00');
    expect(app(ChatbotConversationToken::class)->decode($next['context_token'], 'price:guest')['language'])->toBe($code);
    ChatbotResponseAgent::assertPromptedTimes(3);
    ChatbotIntentAgent::assertNeverPrompted();
})->with([
    ['Pila ini subong?', 'English', 'en'],
    ['Pila kana karon?', 'Cebuano', 'ceb'],
    ['Magkano ito ngayon?', 'Tagalog', 'tl'],
]);
