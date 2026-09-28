<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotCategory;
use App\Enums\ChatbotQueryCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Str;

test('the default seed workflow creates approved chatbot knowledge scenarios', function () {
    $this->seed();

    $knowledge = ChatbotKnowledge::query()
        ->get()
        ->keyBy('question_pattern');

    $this->assertDatabaseCount('chatbot_knowledge', 11);
    expect($knowledge)->toHaveCount(11)
        ->and($knowledge->pluck('is_active')->every(
            fn (bool $isActive): bool => $isActive,
        ))->toBeTrue();

    expect($knowledge['What time does the Sagay store open and close?']->category)->toBe(ChatbotCategory::Store)
        ->and($knowledge['What time does the Sagay store open and close?']->priority)->toBe(100)
        ->and($knowledge['What time does the Sagay store open and close?']->response_template)->toBe(
            "The Sagay City branch's confirmed operating hours are 8:00 AM–6:00 PM.",
        )
        ->and($knowledge['Where is the Sagay City branch located?']->response_template)->toBe(
            'The Sagay City branch is located at A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122.',
        )
        ->and($knowledge['How can I contact the Sagay store?']->response_template)->toBe(
            'You can contact the Sagay City branch at 0938 647 6046 or battlefrontcomputertrading@gmail.com.',
        );

    expect($knowledge['Is this product currently available?']->category)->toBe(ChatbotCategory::Product)
        ->and($knowledge["Do you have the product I saw on Battlefront's Facebook page?"]->category)->toBe(ChatbotCategory::Product)
        ->and($knowledge['Do you have Piso WiFi products available?']->category)->toBe(ChatbotCategory::Product)
        ->and($knowledge['Is this product currently available?']->priority)->toBe(80)
        ->and($knowledge["Do you have the product I saw on Battlefront's Facebook page?"]->priority)->toBe(80)
        ->and($knowledge['Do you have Piso WiFi products available?']->priority)->toBe(80);

    expect($knowledge['How can I check my order status?']->category)->toBe(ChatbotCategory::Order)
        ->and($knowledge['Can I choose pickup or delivery?']->category)->toBe(ChatbotCategory::Order)
        ->and($knowledge['What payment methods can I use?']->category)->toBe(ChatbotCategory::Order)
        ->and($knowledge['How can I check my order status?']->priority)->toBe(70)
        ->and($knowledge['Can I choose pickup or delivery?']->priority)->toBe(70)
        ->and($knowledge['What payment methods can I use?']->priority)->toBe(70)
        ->and($knowledge['What payment methods can I use?']->response_template)->toBe(
            'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.',
        );

    expect($knowledge['How is payment proof verified for each method?']->category)->toBe(ChatbotCategory::Faq)
        ->and($knowledge['How is payment proof verified for each method?']->response_template)->toBe(
            'GCash and Maya require uploaded payment proof, which Battlefront staff review manually. Cash and Card at store are pickup payment methods and do not require an upload; staff confirm those payments manually. Check your order history for the latest payment status.',
        )
        ->and($knowledge['Do you offer real-time delivery tracking?']->category)->toBe(ChatbotCategory::Faq)
        ->and($knowledge['Do you offer real-time delivery tracking?']->response_template)->toBe(
            'Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.',
        );
});

test('rerunning the default seed workflow preserves administrator edits and adds no duplicates', function () {
    $this->seed();
    $paymentKnowledge = ChatbotKnowledge::query()
        ->where('question_pattern', 'What payment methods can I use?')
        ->firstOrFail();
    $paymentKnowledge->update([
        'category' => ChatbotCategory::Faq,
        'response_template' => 'Unsupported replacement guidance.',
        'priority' => -1,
        'is_active' => false,
    ]);

    $this->seed();

    $paymentKnowledge->refresh();
    $this->assertDatabaseCount('chatbot_knowledge', 11);
    expect($paymentKnowledge->category)->toBe(ChatbotCategory::Faq)
        ->and($paymentKnowledge->response_template)->toBe(
            'Unsupported replacement guidance.',
        )
        ->and($paymentKnowledge->priority)->toBe(-1)
        ->and($paymentKnowledge->is_active)->toBeFalse();
});

test('seeded FAQs route to knowledge while referenced customer orders still use the order resolver', function () {
    $this->seed();

    $routing = app(RouteChatbotQuery::class);
    $deliveryQuestion = 'Do you offer real-time delivery tracking?';
    $paymentQuestion = 'How is payment proof verified for each method?';

    expect($routing->execute($deliveryQuestion)['category'])->toBe(ChatbotQueryCategory::Faq)
        ->and($routing->execute($paymentQuestion)['category'])->toBe(ChatbotQueryCategory::Faq)
        ->and($routing->execute('Is my GCash payment for BF-123 verified?')['category'])->toBe(ChatbotQueryCategory::Order)
        ->and(app(ResolveFaqContext::class)->execute($deliveryQuestion)['knowledge'][0]['response_template'])
        ->toBe('Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.');
});

test('payment verification questions use the complete FAQ while method availability uses its own answer', function () {
    $this->seed();

    $knowledge = app(MatchChatbotKnowledge::class);
    foreach ([
        'How is GCash payment verified?',
        'How is Maya payment verified?',
        'Do I need payment proof for Maya?',
        'How do staff verify cash payment?',
        'How is card at store payment verified?',
    ] as $question) {
        expect($knowledge->execute($question))->toHaveCount(1)
            ->and($knowledge->execute($question)[0]['question_pattern'])
            ->toBe('How is payment proof verified for each method?');
    }

    expect($knowledge->execute('Do you accept GCash?'))->toHaveCount(1)
        ->and($knowledge->execute('Do you accept GCash?')[0]['question_pattern'])->toBe('What payment methods can I use?');
});

test('rerunning the seeder replaces only the original unedited GCash sample', function () {
    $original = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How is GCash payment verified?',
        'response_template' => 'GCash payment proof is reviewed manually by Battlefront staff. Check your order history for the updated payment status.',
        'priority' => 60,
    ]);

    $this->seed();

    $this->assertDatabaseCount('chatbot_knowledge', 11);
    expect($original->refresh()->question_pattern)->toBe('How is payment proof verified for each method?')
        ->and($original->response_template)->toContain('GCash and Maya', 'Cash and Card at store');
});

test('seeded chatbot knowledge excludes unsupported policies and stale operational claims', function () {
    $this->seed();

    $seededText = Str::lower(
        ChatbotKnowledge::query()
            ->get(['question_pattern', 'response_template'])
            ->flatMap(fn (ChatbotKnowledge $knowledge): array => [
                $knowledge->question_pattern,
                $knowledge->response_template,
            ])
            ->implode(' '),
    );

    expect($seededText)
        ->not->toContain('warranty')
        ->not->toContain('refund')
        ->not->toContain('return policy')
        ->not->toContain('repair service')
        ->not->toContain('delivery time')
        ->not->toContain('delivery fee')
        ->not->toContain('promotion')
        ->not->toContain('pricing policy')
        ->not->toContain('reservation expiry');
});

test('development chatbot knowledge is not seeded outside local and testing environments', function () {
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn (): string => 'production');

        app(DevelopmentChatbotKnowledgeSeeder::class)->run();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }

    $this->assertDatabaseCount('chatbot_knowledge', 0);
});
