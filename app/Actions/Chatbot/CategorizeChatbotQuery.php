<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotQueryCategory;
use Illuminate\Support\Str;

class CategorizeChatbotQuery
{
    /** @var list<string> */
    private const OPEN_DOMAIN_PHRASES = [
        'who invented',
        'who wrote',
        'what is the capital',
        'tell me a joke',
        'weather',
        'latest news',
        'sports score',
        'solve my homework',
        'write an essay',
        'translate this',
        'recipe',
    ];

    /** @var list<string> */
    private const RECOMMENDATION_PHRASES = [
        'recommend',
        'recommended',
        'recommendation',
        'recommendations',
        'suggest',
        'suggestion',
        'suggestions',
        'should i buy',
        'help me choose',
        'best laptop',
        'best computer',
        'best pc',
        'best product',
        'best mouse',
        'best keyboard',
        'which laptop is best',
        'which product is best',
        'what should i get',
        'which should i get',
        'what is best for',
        'which is best for',
        'for my budget',
        'within my budget',
        'good for gaming',
        'best for gaming',
    ];

    /** @var list<string> */
    private const ORDER_PHRASES = [
        'my order',
        'order status',
        'order tracking',
        'track order',
        'track my order',
        'tracking number',
        'order number',
        'pickup order',
        'pick up order',
        'delivery order',
        'pending',
        'processing',
        'completed',
        'cancelled',
        'canceled',
        'my oder',
        'oder status',
        'track oder',
        'proccessing',
        'procesing',
        'compeleted',
    ];

    /** @var list<string> */
    private const FAQ_PHRASES = [
        'payment',
        'payment method',
        'payment methods',
        'payment option',
        'payment options',
        'ways to pay',
        'how can i pay',
        'how do i pay',
        'pay cash',
        'cash payment',
        'pay by card',
        'card at store',
        'gcash',
        'maya',
        'pickup',
        'pick up',
        'delivery',
        'pickup or delivery',
        'pick up or delivery',
        'do you deliver',
        'fulfillment',
        'paymnt method',
        'paymnt methods',
        'delivry',
    ];

    /** @var list<string> */
    private const PRODUCT_PHRASES = [
        'price',
        'prices',
        'pricing',
        'cost',
        'stock',
        'stocks',
        'stocked',
        'available',
        'availability',
        'product',
        'products',
        'item',
        'items',
        'laptop',
        'laptops',
        'computer',
        'computers',
        'pc',
        'mouse',
        'keyboard',
        'piso wifi',
        'specification',
        'specifications',
        'specs',
        'prcie',
        'stok',
        'availble',
        'avalable',
        'laptp',
        'keybord',
    ];

    /** @var list<string> */
    private const STORE_OPERATIONAL_PHRASES = [
        'location',
        'locations',
        'located',
        'address',
        'contact',
        'phone',
        'telephone',
        'email',
        'hours',
        'store hours',
        'business hours',
        'opening hours',
        'closing hours',
        'open',
        'close',
        'closing',
        'locaton',
        'adress',
        'contct',
        'store ours',
    ];

    /** @var list<string> */
    private const STORE_REFERENCE_PHRASES = [
        'store',
        'branch',
        'branches',
        'sagay store',
        'brach',
    ];

    /**
     * Classify a chatbot message without retrieving data or calling providers.
     */
    public function execute(string $message): ChatbotQueryCategory
    {
        $normalizedMessage = $this->normalize($message);

        if (
            $normalizedMessage === ''
            || $this->containsAny($normalizedMessage, self::OPEN_DOMAIN_PHRASES)
            || $this->containsAny($normalizedMessage, self::RECOMMENDATION_PHRASES)
        ) {
            return ChatbotQueryCategory::Unsupported;
        }

        /** @var list<array{category: ChatbotQueryCategory, phrases: list<string>}> $rules */
        $rules = [
            ['category' => ChatbotQueryCategory::Order, 'phrases' => self::ORDER_PHRASES],
            ['category' => ChatbotQueryCategory::Faq, 'phrases' => self::FAQ_PHRASES],
            ['category' => ChatbotQueryCategory::Store, 'phrases' => self::STORE_OPERATIONAL_PHRASES],
            ['category' => ChatbotQueryCategory::Product, 'phrases' => self::PRODUCT_PHRASES],
            ['category' => ChatbotQueryCategory::Store, 'phrases' => self::STORE_REFERENCE_PHRASES],
        ];

        foreach ($rules as $rule) {
            if ($this->containsAny($normalizedMessage, $rule['phrases'])) {
                return $rule['category'];
            }
        }

        return ChatbotQueryCategory::Unsupported;
    }

    private function normalize(string $message): string
    {
        return Str::of($message)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();
    }

    /**
     * @param  list<string>  $phrases
     */
    private function containsAny(string $message, array $phrases): bool
    {
        $messageWithBoundaries = " {$message} ";

        foreach ($phrases as $phrase) {
            if (str_contains($messageWithBoundaries, " {$phrase} ")) {
                return true;
            }
        }

        return false;
    }
}
