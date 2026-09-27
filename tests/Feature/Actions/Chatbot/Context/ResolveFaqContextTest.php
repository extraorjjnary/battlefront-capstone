<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;

test('returns only relevant active FAQ knowledge', function () {
    $activeFaq = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Which payment methods are accepted?',
        'response_template' => 'Approved payment guidance.',
        'priority' => 20,
    ]);
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Which payment methods are accepted online?',
        'response_template' => 'Inactive guidance.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product,
        'question_pattern' => 'Which payment methods are accepted?',
        'response_template' => 'Wrong category.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Can I choose pickup or delivery?',
        'response_template' => 'Unrelated guidance.',
        'priority' => 100,
    ]);

    $context = (new ResolveFaqContext)->execute('How can I pay with an accepted payment method?');

    expect($context)->toBe([
        'knowledge' => [[
            'question_pattern' => $activeFaq->question_pattern,
            'response_template' => 'Approved payment guidance.',
        ]],
    ]);
});

test('resolves approved managed payment guidance categorized under the order topic', function () {
    $this->seed([
        BranchSeeder::class,
        DevelopmentChatbotKnowledgeSeeder::class,
    ]);

    $context = (new ResolveFaqContext)->execute('Which payment methods can I use?');

    expect($context['knowledge'][0])->toBe([
        'question_pattern' => 'What payment methods can I use?',
        'response_template' => 'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.',
    ]);
});

test('orders FAQ matches by relevance then priority and limits context to three records', function () {
    $mostRelevant = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Payment methods for pickup',
        'response_template' => 'Most relevant.',
        'priority' => 0,
    ]);
    $secondMostRelevant = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Payment methods',
        'response_template' => 'Second most relevant.',
        'priority' => 10,
    ]);
    $higherPriority = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Payment options',
        'response_template' => 'Higher priority.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Payment help',
        'response_template' => 'Lower priority.',
        'priority' => 50,
    ]);

    $context = (new ResolveFaqContext)->execute('Payment methods for pickup');

    expect(array_column($context['knowledge'], 'question_pattern'))->toBe([
        $mostRelevant->question_pattern,
        $higherPriority->question_pattern,
        $secondMostRelevant->question_pattern,
    ]);
});

test('returns explicit empty FAQ context when no managed knowledge matches', function (string $message) {
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
    ]);

    $context = (new ResolveFaqContext)->execute($message);

    expect($context)->toBe(['knowledge' => []]);
})->with([
    'empty input' => ['  ...  '],
    'no relevant knowledge' => ['Do you provide technical training?'],
]);
