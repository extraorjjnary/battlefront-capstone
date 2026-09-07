<?php

namespace App\Http\Requests\Administration;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
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
        $category = $this->route('category');
        $uniqueName = Rule::unique(Category::class);

        if ($category instanceof Category) {
            $uniqueName->ignore($category);
        }

        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                $uniqueName,
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get the validation messages for category details.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a category name.',
            'name.unique' => 'A category with this name already exists.',
            'name.max' => 'The category name must not exceed 255 characters.',
            'description.max' => 'The description must not exceed 2,000 characters.',
        ];
    }
}
