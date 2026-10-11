<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotKnowledgePreviewController extends Controller
{
    public function __invoke(
        Request $request,
        RouteChatbotQuery $router,
        MatchChatbotKnowledge $matcher,
        ClassifyChatbotInquiry $boundary,
    ): JsonResponse {
        $validated = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        if ($boundary->containsSensitiveInput($validated['message'])) {
            return response()->json([
                'category' => null,
                'owner' => 'unsupported',
                'eligible_for_faq' => false,
                'ambiguous' => false,
                'matches' => [],
                'explanation' => 'Remove sensitive information before testing this question.',
            ]);
        }

        $routing = $router->execute($validated['message']);
        $category = $routing['category'];
        $classification = $boundary->execute($validated['message']);
        $matches = $classification['eligible_for_faq'] && $category === ChatbotQueryCategory::Faq && $routing['choices'] === []
            ? $matcher->bestMatches($validated['message']) : [];

        return response()->json([
            'category' => $category->value,
            'owner' => $routing['choices'] !== [] ? 'clarification' : $classification['owner'],
            'eligible_for_faq' => $classification['eligible_for_faq'] && $routing['choices'] === [] && $matcher->hasMeaningfulTerms($validated['message']),
            'ambiguous' => count($matches) > 1,
            'matches' => $matches,
            'explanation' => $this->explanation($routing, $classification, $matches, $matcher->hasMeaningfulTerms($validated['message']), $router),
        ]);
    }

    /**
     * @param  array{category: ChatbotQueryCategory, choices: array<string, string>}  $routing
     * @param  array<string, mixed>  $classification
     * @param  list<array<string, mixed>>  $matches
     */
    private function explanation(array $routing, array $classification, array $matches, bool $hasTerms, RouteChatbotQuery $router): string
    {
        if ($routing['choices'] !== []) {
            return $router->clarification($routing['choices']);
        }
        if (! $classification['eligible_for_faq']) {
            return $classification['reason'];
        }
        if (! $hasTerms) {
            return 'Enter a specific Battlefront support question with meaningful wording.';
        }
        if (count($matches) > 1) {
            return 'Multiple FAQs match equally. The chatbot will ask which question you mean.';
        }

        return $matches === []
            ? 'No active approved FAQ matches this question. Eligible wording can be saved as a new FAQ.'
            : 'Matched active saved FAQ. Exact wording ranks first, then relevance. Priority does not affect matching.';
    }
}
