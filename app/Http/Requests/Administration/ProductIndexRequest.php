<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the validation messages for product filters.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Search must be text.',
            'q.max' => 'Search may not be longer than 255 characters.',
            'category_id.integer' => 'Select a valid category.',
            'category_id.exists' => 'Select a valid category.',
            'brand.string' => 'Select a valid brand.',
            'brand.max' => 'Select a valid brand.',
            'tag_id.integer' => 'Select a valid tag.',
            'tag_id.exists' => 'Select a valid tag.',
            'status.in' => 'Select a valid product status.',
            'page.integer' => 'The product page must be a whole number.',
            'page.min' => 'The product page must be at least 1.',
        ];
    }
}
