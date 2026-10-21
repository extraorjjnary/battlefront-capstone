<?php

namespace App\Http\Requests\Administration;

use App\Actions\Chatbot\ValidateChatbotFaq;
use App\Models\ChatbotKnowledge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveChatbotKnowledgeRequest extends FormRequest
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
            'question_pattern' => ['bail', 'required', 'string', 'max:255'],
            'response_template' => ['bail', 'required', 'string'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('question_pattern')) {
                return;
            }
            /** @var ChatbotKnowledge|null $record */
            $record = $this->route('chatbotKnowledge');
            $error = app(ValidateChatbotFaq::class)->execute($this->string('question_pattern')->toString(), $record?->id);
            if ($error !== null) {
                $validator->errors()->add('question_pattern', $error);
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'question_pattern.required' => 'Enter a question pattern.',
            'question_pattern.max' => 'The question pattern must not exceed 255 characters.',
            'response_template.required' => 'Enter a response template.',
        ];
    }
}
