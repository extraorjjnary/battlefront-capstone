<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\User;
use App\Services\Chatbot\ChatbotAiAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Prompts\AgentPrompt;

test('returns generated wording from minimized authoritative context', function () {
    Http::preventStrayRequests();
    config()->set('ai.providers.gemini.key', 'test-secret-that-must-not-be-forwarded');
    ChatbotResponseAgent::fake([
        'The Aurelius Link Station is currently available at the Sagay store.',
    ])->preventStrayPrompts();
    $context = [
        'products' => [[
            'name' => 'Aurelius Link Station',
            'price' => '12999.00',
            'inventory' => [
                'quantity' => 7,
                'status' => 'in_stock',
            ],
        ]],
    ];

    $result = app(ChatbotAiAdapter::class)->generate(
        'Is the Aurelius Link Station available?',
        $context,
    );

    expect($result)->toBe([
        'successful' => true,
        'text' => 'The Aurelius Link Station is currently available at the Sagay store.',
        'failure' => null,
    ]);
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($context): bool {
        $encodedContext = json_encode(
            $context,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $prompt->provider()->name() === 'gemini'
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->timeout === 20
            && str_contains($prompt->prompt, '"Is the Aurelius Link Station available?"')
            && str_contains($prompt->prompt, $encodedContext)
            && ! str_contains($prompt->prompt, 'test-secret-that-must-not-be-forwarded');
    });
    Http::assertNothingSent();
});

test('returns a safe timeout result for provider connection failures', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw ProviderConnectionException::forProvider(
        'gemini',
        previous: new RuntimeException('sensitive transport detail'),
    ))->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->generate('Where is the Sagay store?', [
        'branches' => [],
    ]);

    expect($result)->toBe([
        'successful' => false,
        'text' => null,
        'failure' => 'timeout',
    ])->not->toContain('sensitive transport detail');
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('returns a safe unavailable result without logging provider details', function () {
    Http::preventStrayRequests();
    Log::spy();
    ChatbotResponseAgent::fake(
        fn () => throw new AiException('provider response containing test-secret'),
    )->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->generate('What payment methods are accepted?', [
        'knowledge' => [],
    ]);

    expect($result)->toBe([
        'successful' => false,
        'text' => null,
        'failure' => 'provider_unavailable',
    ])->not->toContain('provider response containing test-secret');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('returns an unavailable result when the provider returns blank wording', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['   '])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->generate('Where is the Sagay store?', [
        'branches' => [],
    ]);

    expect($result)->toBe([
        'successful' => false,
        'text' => null,
        'failure' => 'provider_unavailable',
    ]);
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('rejects eloquent models before prompting the provider', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    expect(fn () => app(ChatbotAiAdapter::class)->generate('Show my order.', [
        'customer' => new User,
    ]))->toThrow(
        InvalidArgumentException::class,
        'The prepared chatbot context may contain only arrays, scalar values, and null.',
    );
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('rejects blank prepared prompts before prompting the provider', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    expect(fn () => app(ChatbotAiAdapter::class)->generate('   ', []))->toThrow(
        InvalidArgumentException::class,
        'The prepared chatbot prompt must not be blank.',
    );
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});
