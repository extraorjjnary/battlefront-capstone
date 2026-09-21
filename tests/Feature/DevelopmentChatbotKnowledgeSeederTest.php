<?php

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Str;

test('the default seed workflow creates approved chatbot knowledge scenarios', function () {
    $this->seed();

    $knowledge = ChatbotKnowledge::query()
        ->get()
        ->keyBy('question_pattern');

    $this->assertDatabaseCount('chatbot_knowledge', 9);
    expect($knowledge)->toHaveCount(9)
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
});

test('the default seed workflow can be rerun without duplicates and restores its chatbot knowledge', function () {
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
    $this->assertDatabaseCount('chatbot_knowledge', 9);
    expect($paymentKnowledge->category)->toBe(ChatbotCategory::Order)
        ->and($paymentKnowledge->response_template)->toBe(
            'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.',
        )
        ->and($paymentKnowledge->priority)->toBe(70)
        ->and($paymentKnowledge->is_active)->toBeTrue();
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
