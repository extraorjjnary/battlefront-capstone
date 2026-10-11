<?php

namespace App\Actions\Chatbot\Context;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\MatchChatbotKnowledge;

class ResolveFaqContext
{
    public function __construct(
        private MatchChatbotKnowledge $knowledge = new MatchChatbotKnowledge,
        private ClassifyChatbotInquiry $boundary = new ClassifyChatbotInquiry,
    ) {}

    /** @return array{knowledge: list<array{question_pattern: string, response_template: string}>} */
    public function execute(string $message): array
    {
        if (! $this->boundary->execute($message)['eligible_for_faq']) {
            return ['knowledge' => []];
        }

        return ['knowledge' => array_map(fn (array $match): array => [
            'question_pattern' => $match['question_pattern'],
            'response_template' => $match['response_template'],
        ], $this->knowledge->bestMatches($message))];
    }
}
