<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('administrators preview the same saved payment knowledge used by the customer chatbot', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $administrator = User::factory()->administrator()->create();
    $knowledge = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
        'response_template' => 'Approved payment guidance.',
    ]);

    $this->actingAs($administrator)->postJson(route('administration.chatbot-knowledge.preview'), [
        'message' => 'Do you accept GCash?',
    ])->assertOk()
        ->assertJsonPath('category', 'faq')
        ->assertJsonMissingPath('record_matches')
        ->assertJsonPath('matches.0.id', $knowledge->id)
        ->assertJsonPath('matches.0.response_template', 'Approved payment guidance.');

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
    $this->assertDatabaseHas('chatbot_knowledge', ['id' => $knowledge->id, 'response_template' => 'Approved payment guidance.']);
});

test('preview excludes inactive knowledge and never resolves personal orders', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $administrator = User::factory()->administrator()->create();
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'What payment methods can I use?',
    ]);

    $this->actingAs($administrator)->postJson(route('administration.chatbot-knowledge.preview'), [
        'message' => 'Do you accept GCash?',
    ])->assertOk()->assertJsonPath('matches', [])->assertJsonMissingPath('record_matches');
    $this->postJson(route('administration.chatbot-knowledge.preview'), [
        'message' => 'Is my payment for BF-42 verified?',
    ])->assertOk()->assertJsonPath('category', 'order')->assertJsonPath('matches', [])
        ->assertJsonMissingPath('orders');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('guests and customers cannot preview admin knowledge', function () {
    $url = route('administration.chatbot-knowledge.preview');

    $this->postJson($url, ['message' => 'Payment?'])->assertUnauthorized();
    $this->actingAs(User::factory()->customer()->create())
        ->postJson($url, ['message' => 'Payment?'])->assertForbidden();
});

test('preview validates questions and explains mixed topics', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $this->actingAs(User::factory()->administrator()->create());
    $url = route('administration.chatbot-knowledge.preview');

    $this->postJson($url, ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->postJson($url, ['message' => str_repeat('x', 1001)])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->postJson($url, ['message' => 'Delivery fee and laptop price?'])
        ->assertOk()->assertJsonPath('matches', [])
        ->assertJsonPath('explanation', fn (string $message): bool => str_contains($message, 'Which would you like'));
    ChatbotResponseAgent::assertNeverPrompted();
});

test('the general preview finds different answers beyond the visible list page', function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    ChatbotKnowledge::factory()->count(15)->create(['category' => ChatbotCategory::Product]);
    $payment = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
    ]);
    $delivery = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'Can I choose pickup or delivery?',
    ]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.chatbot-knowledge.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/ChatbotKnowledge/Index')
            ->has('knowledge.data', 15)
            ->where('knowledge.total', 17));
    $this->postJson(route('administration.chatbot-knowledge.preview'), ['message' => 'Do you accept GCash?'])
        ->assertOk()->assertJsonPath('matches.0.id', $payment->id)->assertJsonMissingPath('record_matches');
    $this->postJson(route('administration.chatbot-knowledge.preview'), ['message' => 'Can I choose pickup or delivery?'])
        ->assertOk()->assertJsonPath('matches.0.id', $delivery->id)->assertJsonMissingPath('record_matches');

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('preview explains questions without knowledge matches even when no records exist', function (string $message, string $category, string $explanation) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    $this->actingAs(User::factory()->administrator()->create())
        ->postJson(route('administration.chatbot-knowledge.preview'), ['message' => $message])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('category', $category)
        ->assertJsonPath('matches', [])
        ->assertJsonPath('explanation', fn (string $value): bool => str_contains($value, $explanation));

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    ['Do you accept GCash?', 'faq', 'No active approved knowledge'],
    ['What is the laptop price?', 'product', 'current catalog and Sagay inventory'],
    ['Where is your Sagay branch?', 'store', 'confirmed branch information'],
    ['Tell me a joke', 'unsupported', 'unsupported'],
]);
