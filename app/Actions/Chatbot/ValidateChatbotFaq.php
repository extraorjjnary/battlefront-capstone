<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Eloquent\Builder;

class ValidateChatbotFaq
{
    public function __construct(private ClassifyChatbotInquiry $boundary, private MatchChatbotKnowledge $matcher) {}

    public function execute(string $question, ?int $ignoreId = null): ?string
    {
        $classification = $this->boundary->execute($question);
        if (! $classification['eligible_for_faq']) {
            return $classification['reason'];
        }
        if (! $this->matcher->hasMeaningfulTerms($question)) {
            return 'Enter a specific Battlefront support question with meaningful wording.';
        }
        $normalized = $this->matcher->normalize($question);
        $duplicate = ChatbotKnowledge::query()->where('category', ChatbotCategory::Faq->value)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $ignoreId))
            ->pluck('question_pattern')->contains(fn (string $pattern): bool => $this->matcher->normalize($pattern) === $normalized);

        return $duplicate ? 'An FAQ with this question already exists. Edit or reactivate that record instead.' : null;
    }
}
