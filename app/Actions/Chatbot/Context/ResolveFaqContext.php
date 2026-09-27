<?php

namespace App\Actions\Chatbot\Context;

use App\Actions\Chatbot\MatchChatbotKnowledge;

class ResolveFaqContext
{
    public function __construct(private MatchChatbotKnowledge $knowledge = new MatchChatbotKnowledge) {}

    /** @return array{knowledge: list<array{question_pattern: string, response_template: string}>} */
    public function execute(string $message): array
    {
        return ['knowledge' => array_map(fn (array $match): array => [
            'question_pattern' => $match['question_pattern'],
            'response_template' => $match['response_template'],
        ], $this->knowledge->execute($message))];
    }
}
