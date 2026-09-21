<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResolveFaqContext
{
    /** @var list<string> */
    private const QUERY_WORDS = [
        'a',
        'about',
        'an',
        'are',
        'can',
        'could',
        'do',
        'does',
        'how',
        'i',
        'is',
        'me',
        'my',
        'please',
        'tell',
        'the',
        'what',
        'which',
        'you',
        'your',
    ];

    /**
     * Resolve relevant active FAQ knowledge without generating response wording.
     *
     * @return array{knowledge: list<array{question_pattern: string, response_template: string}>}
     */
    public function execute(string $message): array
    {
        $terms = $this->meaningfulTerms($message);

        if ($terms === []) {
            return ['knowledge' => []];
        }

        $matches = ChatbotKnowledge::query()
            ->active()
            ->whereIn('category', [
                ChatbotCategory::Faq->value,
                ChatbotCategory::Order->value,
            ])
            ->select(['id', 'question_pattern', 'response_template', 'priority'])
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhereLike('question_pattern', "%{$term}%");
                }
            })
            ->get()
            ->map(fn (ChatbotKnowledge $knowledge): array => [
                'knowledge' => $knowledge,
                'relevance' => $this->relevance($knowledge->question_pattern, $terms),
            ])
            ->sort(function (array $left, array $right): int {
                return ($right['relevance'] <=> $left['relevance'])
                    ?: ($right['knowledge']->priority <=> $left['knowledge']->priority)
                    ?: ($left['knowledge']->id <=> $right['knowledge']->id);
            })
            ->take(3);

        return [
            'knowledge' => array_values($matches
                ->map(fn (array $match): array => [
                    'question_pattern' => $match['knowledge']->question_pattern,
                    'response_template' => $match['knowledge']->response_template,
                ])
                ->all()),
        ];
    }

    /**
     * @return list<string>
     */
    private function meaningfulTerms(string $message): array
    {
        $normalizedMessage = $this->normalize($message);

        if ($normalizedMessage === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalizedMessage),
            fn (string $term): bool => Str::length($term) >= 2
                && ! in_array($term, self::QUERY_WORDS, strict: true),
        )));
    }

    /**
     * @param  list<string>  $terms
     */
    private function relevance(string $pattern, array $terms): int
    {
        $normalizedPattern = $this->normalize($pattern);

        return Collection::make($terms)
            ->filter(fn (string $term): bool => Str::contains($normalizedPattern, $term))
            ->count();
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();
    }
}
