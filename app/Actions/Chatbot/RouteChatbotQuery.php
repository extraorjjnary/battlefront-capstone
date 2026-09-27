<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotQueryCategory;

class RouteChatbotQuery
{
    public function __construct(
        private CategorizeChatbotQuery $categorizer,
        private MatchChatbotKnowledge $knowledge,
    ) {}

    /** @return array{category: ChatbotQueryCategory, choices: array<string, string>} */
    public function execute(string $message): array
    {
        $category = $this->categorizer->execute($message);

        if ($this->categorizer->isBlocked($message)) {
            return ['category' => $category, 'choices' => []];
        }

        $choices = [];
        $clauses = preg_split('/\s+(?:and|also|plus)\s+|[;?]+\s*(?:(?:and|also|plus)\s+)?/iu', $message, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($clauses as $clause) {
            $clauseCategory = $this->categoryWithKnowledge($clause);

            if ($clauseCategory !== ChatbotQueryCategory::Unsupported) {
                $choices[$clauseCategory->value] = isset($choices[$clauseCategory->value])
                    ? $choices[$clauseCategory->value].' and '.trim($clause)
                    : trim($clause);
            }
        }

        if (count($choices) > 1) {
            return ['category' => $category, 'choices' => $choices];
        }

        return ['category' => $this->categoryWithKnowledge($message), 'choices' => []];
    }

    private function categoryWithKnowledge(string $message): ChatbotQueryCategory
    {
        $category = $this->categorizer->execute($message);

        if (! $this->categorizer->isBlocked($message)
            && $category !== ChatbotQueryCategory::Order
            && ($category === ChatbotQueryCategory::Faq || ! $this->categorizer->requiresLiveFacts($message))
            && $this->knowledge->execute($message, exactOnly: true) !== []) {
            $category = ChatbotQueryCategory::Faq;
        }

        return $category;
    }

    /** @param array<string, string> $choices */
    public function clarification(array $choices): string
    {
        return 'Which would you like to ask about first: '.implode(' or ', array_map(
            fn (string $category): string => match ($category) {
                'product' => 'product information',
                'order' => 'your order',
                'store' => 'store information',
                default => 'payment or delivery guidance',
            }, array_keys($choices),
        )).'? Please repeat that part of your question or name its topic.';
    }
}
