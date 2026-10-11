<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateChatbotKnowledgeActivationRequest;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ChatbotKnowledgeActivationController extends Controller
{
    public function __invoke(
        UpdateChatbotKnowledgeActivationRequest $request,
        ChatbotKnowledge $chatbotKnowledge,
    ): RedirectResponse {
        $isActive = $request->boolean('is_active');

        $chatbotKnowledge->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive
                ? __('Chatbot FAQ reactivated.')
                : __('Chatbot FAQ deactivated.'),
        ]);

        return back();
    }
}
