<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Support\Str;

class MatchChatbotKnowledge
{
    /** @var list<string> */
    private const QUERY_WORDS = ['a', 'about', 'an', 'and', 'are', 'at', 'be', 'can', 'could', 'do', 'does', 'for', 'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'or', 'please', 'tell', 'the', 'to', 'use', 'what', 'which', 'with', 'you', 'your'];

    /**
     * Match approved question wording only; answer text is never a routing signal.
     *
     * @return list<array{id: int, question_pattern: string, response_template: string, priority: int, relevance: int, exact: bool}>
     */
    public function execute(string $message, bool $exactOnly = false): array
    {
        $normalized = $this->normalize($message);
        $terms = $this->terms($message);

        if ($normalized === '' || $terms === []) {
            return [];
        }

        return array_values(ChatbotKnowledge::query()->active()
            ->whereIn('category', [ChatbotCategory::Faq->value, ChatbotCategory::Order->value])
            ->select(['id', 'question_pattern', 'response_template', 'priority'])
            ->get()
            ->map(function (ChatbotKnowledge $knowledge) use ($normalized, $terms): array {
                return [
                    'id' => $knowledge->id,
                    'question_pattern' => $knowledge->question_pattern,
                    'response_template' => $knowledge->response_template,
                    'priority' => $knowledge->priority,
                    'relevance' => count(array_intersect($terms, $this->terms($knowledge->question_pattern))),
                    'exact' => $normalized === $this->normalize($knowledge->question_pattern),
                ];
            })
            ->filter(fn (array $match): bool => $match['exact'] || (! $exactOnly && $match['relevance'] >= min(2, count($terms))))
            ->sort(fn (array $left, array $right): int => ($right['exact'] <=> $left['exact'])
                ?: ($right['relevance'] <=> $left['relevance'])
                ?: ($right['priority'] <=> $left['priority'])
                ?: ($left['id'] <=> $right['id']))
            ->take(3)->all());
    }

    /** @return list<string> */
    private function terms(string $message): array
    {
        $message = $this->normalize($message);
        $message = preg_replace('/\b(?:payment methods?|payment options?|ways to pay|how (?:can|do|should) i pay|how to pay|pay by card|card at store|cash payment|gcash|maya|pay cash|paymnt methods?)\b/u', 'payment method', $message);
        $message = preg_replace('/\b(?:accept|accepted|accepts|supported|support|offer|offered)\b/u', '', $message);
        $message = preg_replace('/\b(?:pick up|collection)\b/u', 'pickup', $message);
        $message = preg_replace('/\b(?:deliver|delivry)\b/u', 'delivery', $message);

        return array_values(array_unique(array_filter(explode(' ', $message), fn (string $term): bool => Str::length($term) >= 2 && ! in_array($term, self::QUERY_WORDS, true))));
    }

    private function normalize(string $message): string
    {
        return Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
    }
}
