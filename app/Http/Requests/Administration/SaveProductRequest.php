<?php

namespace App\Http\Requests\Administration;

use App\Models\Category;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
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
            'name' => ['bail', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists(Category::class, 'id'),
            ],
            'brand' => ['bail', 'required', 'string', 'max:255'],
            'price' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],
            'is_featured' => ['required', 'boolean'],
            'discount_price' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'lt:price',
                'max:9999999999.99',
            ],
            'image_url' => ['nullable', 'url:http,https', 'max:255'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Tag::class, 'id'),
            ],
        ];
    }

    /**
     * Get the validation messages for product details.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a product name.',
            'category_id.required' => 'Select a category.',
            'category_id.exists' => 'Select a valid category.',
            'brand.required' => 'Enter the product brand.',
            'price.required' => 'Enter the regular price.',
            'price.numeric' => 'The regular price must be a number.',
            'price.decimal' => 'Use no more than two decimal places for the regular price.',
            'price.min' => 'The regular price must be zero or greater.',
            'is_featured.required' => 'Choose whether this is a featured product.',
            'discount_price.numeric' => 'The discount price must be a number.',
            'discount_price.decimal' => 'Use no more than two decimal places for the discount price.',
            'discount_price.min' => 'The discount price must be zero or greater.',
            'discount_price.lt' => 'The discount price must be lower than the regular price.',
            'image_url.url' => 'Enter a valid HTTP or HTTPS image URL.',
            'tag_ids.array' => 'Select product tags as a list.',
            'tag_ids.*.distinct' => 'Each tag may only be selected once.',
            'tag_ids.*.exists' => 'Select valid product tags.',
        ];
    }
}
