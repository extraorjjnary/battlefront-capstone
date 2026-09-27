<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Http\Controllers\Controller;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotKnowledgePreviewController extends Controller
{
    public function __invoke(
        Request $request,
        RouteChatbotQuery $router,
        MatchChatbotKnowledge $matcher,
        ChatbotOrchestrationService $chatbot,
    ): JsonResponse {
        $validated = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        if ($chatbot->containsSensitiveInput($validated['message'])) {
            return response()->json([
                'category' => null,
                'matches' => [],
                'explanation' => 'Remove sensitive information before testing this question.',
            ]);
        }

        $routing = $router->execute($validated['message']);
        $category = $routing['category'];
        $matches = $category === ChatbotQueryCategory::Faq && $routing['choices'] === []
            ? $matcher->execute($validated['message']) : [];

        return response()->json([
            'category' => $category->value,
            'matches' => $matches,
            'explanation' => $routing['choices'] !== []
                ? $router->clarification($routing['choices'])
                : match ($category) {
                    ChatbotQueryCategory::Product => 'Product answers use current catalog and Sagay inventory facts, not knowledge templates.',
                    ChatbotQueryCategory::Store => 'Store answers use confirmed branch information, not knowledge templates.',
                    ChatbotQueryCategory::Order => 'Personal order inquiries require a customer account and an owned order reference. This preview does not retrieve orders.',
                    ChatbotQueryCategory::Unsupported => 'This wording is unsupported. An active FAQ must match the saved question wording or supported routing rules.',
                    ChatbotQueryCategory::Faq => $matches === []
                        ? 'No active approved knowledge matches this question.'
                        : 'Matched active saved knowledge. Exact wording ranks first, then relevance; priority breaks equal-relevance ties.',
                },
        ]);
    }
}
