<?php

namespace App\Http\Controllers\Administration;

use App\Enums\ChatbotCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveChatbotKnowledgeRequest;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ChatbotKnowledgeController extends Controller
{
    public function index(): Response
    {
        $knowledge = ChatbotKnowledge::query()
            ->select([
                'id',
                'category',
                'question_pattern',
                'response_template',
                'priority',
                'is_active',
            ])
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ChatbotKnowledge $item): array => $this->knowledgeData($item));

        return Inertia::render('Administration/ChatbotKnowledge/Index', [
            'knowledge' => $knowledge,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(SaveChatbotKnowledgeRequest $request): RedirectResponse
    {
        ChatbotKnowledge::query()->create($request->safe()->only([
            'category',
            'question_pattern',
            'response_template',
            'priority',
        ]));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Chatbot knowledge created.'),
        ]);

        return to_route('administration.chatbot-knowledge.index');
    }

    public function show(ChatbotKnowledge $chatbotKnowledge): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Show', [
            'knowledge' => $this->knowledgeData($chatbotKnowledge),
        ]);
    }

    public function edit(ChatbotKnowledge $chatbotKnowledge): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Edit', [
            'knowledge' => $this->knowledgeData($chatbotKnowledge),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(
        SaveChatbotKnowledgeRequest $request,
        ChatbotKnowledge $chatbotKnowledge,
    ): RedirectResponse {
        $chatbotKnowledge->update($request->safe()->only([
            'category',
            'question_pattern',
            'response_template',
            'priority',
        ]));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Chatbot knowledge updated.'),
        ]);

        return to_route('administration.chatbot-knowledge.show', $chatbotKnowledge);
    }

    /**
     * @return array{
     *     id: int,
     *     category: string,
     *     category_label: string,
     *     question_pattern: string,
     *     response_template: string,
     *     priority: int,
     *     is_active: bool
     * }
     */
    private function knowledgeData(ChatbotKnowledge $chatbotKnowledge): array
    {
        return [
            'id' => $chatbotKnowledge->id,
            'category' => $chatbotKnowledge->category->value,
            'category_label' => $chatbotKnowledge->category->label(),
            'question_pattern' => $chatbotKnowledge->question_pattern,
            'response_template' => $chatbotKnowledge->response_template,
            'priority' => $chatbotKnowledge->priority,
            'is_active' => $chatbotKnowledge->is_active,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function categoryOptions(): array
    {
        return array_map(
            fn (ChatbotCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            ChatbotCategory::cases(),
        );
    }
}
