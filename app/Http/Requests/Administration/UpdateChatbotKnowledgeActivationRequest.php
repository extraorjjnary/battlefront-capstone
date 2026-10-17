<?php

namespace App\Http\Requests\Administration;

use App\Actions\Chatbot\ValidateChatbotFaq;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateChatbotKnowledgeActivationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active')) {
                return;
            }
            $record = $this->route('chatbotKnowledge');
            if (! $record instanceof ChatbotKnowledge) {
                return;
            }
            $error = $record->category !== ChatbotCategory::Faq
                ? 'Edit this legacy record into an eligible FAQ before reactivating it.'
                : app(ValidateChatbotFaq::class)->execute($record->question_pattern, $record->id);
            if ($error === null && trim($record->response_template) === '') {
                $error = 'Add an approved answer before reactivating this FAQ.';
            }
            if ($error !== null) {
                $validator->errors()->add('is_active', $error);
            }
        }];
    }
}
