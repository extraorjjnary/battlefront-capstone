<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatbotMessageRequest;
use App\Http\Resources\Api\V1\ChatbotResponseResource;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;

class ChatbotController extends Controller
{
    public function store(StoreChatbotMessageRequest $request, ChatbotConversation $chatbot): ChatbotResponseResource
    {
        /** @var User $customer */
        $customer = $request->user();
        $accessToken = $customer->currentAccessToken();
        $validated = $request->validated();

        return new ChatbotResponseResource($chatbot->respond(
            $validated['message'],
            $customer,
            $validated['context_token'] ?? null,
            'api:token:'.$accessToken->getKey(),
        ));
    }
}
