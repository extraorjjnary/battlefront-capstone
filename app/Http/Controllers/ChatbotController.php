<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function store(Request $request, ChatbotOrchestrationService $chatbot): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        /** @var User|null $customer */
        $customer = $request->user();
        $result = $chatbot->respond($validated['message'], $customer);

        return response()->json([
            'message' => $result['message'],
            'source' => $result['source'],
        ]);
    }
}
