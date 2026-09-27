<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function store(Request $request, ChatbotConversation $chatbot): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'context_token' => ['nullable', 'string', 'max:16384'],
        ]);

        /** @var User|null $customer */
        $customer = $request->user();
        $result = $chatbot->respond($validated['message'], $customer, $validated['context_token'] ?? null, $request->session()->getId());

        return response()->json([
            'message' => $result['message'],
            'source' => $result['source'],
            'context_token' => $result['context_token'],
        ]);
    }
}
