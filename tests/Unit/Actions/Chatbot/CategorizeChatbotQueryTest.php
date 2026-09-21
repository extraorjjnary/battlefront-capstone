<?php

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use Illuminate\Support\Str;

test('representative wording is classified without requiring seeded question text', function (
    string $message,
    ChatbotQueryCategory $expectedCategory,
) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe($expectedCategory);
})->with([
    'product price' => ['How much does a laptop cost?', ChatbotQueryCategory::Product],
    'product stock' => ['Do you still have wireless mice in stock?', ChatbotQueryCategory::Product],
    'Piso WiFi product' => ['Are there Piso WiFi units available?', ChatbotQueryCategory::Product],
    'order tracking' => ['Can I track my order?', ChatbotQueryCategory::Order],
    'order processing' => ['Why is my order still processing?', ChatbotQueryCategory::Order],
    'store address' => ['What is the address of your Sagay branch?', ChatbotQueryCategory::Store],
    'store schedule' => ['What time does the store open?', ChatbotQueryCategory::Store],
    'payment methods FAQ' => ['Which ways to pay do you accept?', ChatbotQueryCategory::Faq],
    'fulfillment FAQ' => ['Do you offer pickup or delivery?', ChatbotQueryCategory::Faq],
    'unsupported open domain' => ['Who wrote Noli Me Tangere?', ChatbotQueryCategory::Unsupported],
    'open domain with product word' => ['Who invented the computer mouse?', ChatbotQueryCategory::Unsupported],
]);

test('case whitespace and punctuation are normalized before matching', function (
    string $message,
    ChatbotQueryCategory $expectedCategory,
) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe($expectedCategory);
})->with([
    'mixed case and whitespace' => ['   IS THIS LAPTOP AVAILABLE???   ', ChatbotQueryCategory::Product],
    'hyphenated product wording' => ['Do you sell Piso-WiFi products?', ChatbotQueryCategory::Product],
    'punctuated order wording' => ['Order-status... please!', ChatbotQueryCategory::Order],
    'punctuated FAQ wording' => ['GCash/Maya???', ChatbotQueryCategory::Faq],
]);

test('bounded typo aliases classify common simple misspellings', function (
    string $message,
    ChatbotQueryCategory $expectedCategory,
) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe($expectedCategory);
})->with([
    'product price typo' => ['What is the prcie?', ChatbotQueryCategory::Product],
    'product availability typo' => ['Is the keybord availble?', ChatbotQueryCategory::Product],
    'order status typo' => ['What is my oder status?', ChatbotQueryCategory::Order],
    'order processing typo' => ['My order is proccessing.', ChatbotQueryCategory::Order],
    'store address typo' => ['What is your adress?', ChatbotQueryCategory::Store],
    'store location typo' => ['Send your locaton.', ChatbotQueryCategory::Store],
    'payment typo' => ['What paymnt methods do you accept?', ChatbotQueryCategory::Faq],
    'delivery typo' => ['Do you offer delivry?', ChatbotQueryCategory::Faq],
]);

test('empty punctuation only and unmatched messages use the unsupported fallback', function (string $message) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe(ChatbotQueryCategory::Unsupported);
})->with([
    'empty' => [''],
    'whitespace' => ['     '],
    'punctuation only' => ['...?!'],
    'unmatched greeting' => ['Good morning'],
]);

test('recommendation requests remain unsupported even when they contain product terms', function (string $message) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe(ChatbotQueryCategory::Unsupported);
})->with([
    'direct recommendation' => ['Can you recommend an available laptop?'],
    'purchase advice' => ['Which keyboard should I buy?'],
    'selection help' => ['Help me choose a gaming mouse.'],
    'best product' => ['What is the best laptop for school?'],
]);

test('ambiguous messages follow the documented category precedence', function (
    string $message,
    ChatbotQueryCategory $expectedCategory,
) {
    $category = (new CategorizeChatbotQuery)->execute($message);

    expect($category)->toBe($expectedCategory);
})->with([
    'order before product' => ['Is my laptop order processing?', ChatbotQueryCategory::Order],
    'FAQ before product' => ['What payment methods are available?', ChatbotQueryCategory::Faq],
    'operational store phrase before product' => ['Where is Battlefront Computer Trading located?', ChatbotQueryCategory::Store],
    'product before store' => ['Is this laptop available at the Sagay branch?', ChatbotQueryCategory::Product],
    'order before FAQ' => ['Is my pickup order completed?', ChatbotQueryCategory::Order],
]);

test('identical input always returns an identical category', function () {
    $categorizer = new CategorizeChatbotQuery;
    $results = [];

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $results[] = $categorizer->execute('Is a Piso-WiFi product available?')->value;
    }

    expect(array_values(array_unique($results)))->toBe([
        ChatbotQueryCategory::Product->value,
    ]);
});

arch('chatbot query categorization remains provider independent')
    ->expect(CategorizeChatbotQuery::class)
    ->toOnlyUse([
        ChatbotQueryCategory::class,
        Str::class,
    ]);
