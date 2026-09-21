<?php

namespace App\Http\Requests\Administration;

use App\Enums\ChatbotCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'category' => ['bail', 'required', Rule::enum(ChatbotCategory::class)],
            'question_pattern' => ['bail', 'required', 'string', 'max:255'],
            'response_template' => ['bail', 'required', 'string'],
            'priority' => [
                'bail',
                'required',
                'integer',
                'between:-2147483648,2147483647',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category.required' => 'Select a chatbot category.',
            'category.enum' => 'Select a valid chatbot category.',
            'question_pattern.required' => 'Enter a question pattern.',
            'question_pattern.max' => 'The question pattern must not exceed 255 characters.',
            'response_template.required' => 'Enter a response template.',
            'priority.required' => 'Enter a priority.',
            'priority.integer' => 'The priority must be a whole number.',
            'priority.between' => 'The priority is outside the supported range.',
        ];
    }
}
