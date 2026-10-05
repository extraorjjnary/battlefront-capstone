<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCatalogIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize validated catalog filters for web and API queries.
     *
     * @return array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null, category_ids?: list<int>, min_price?: string, max_price?: string, sort?: string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'brand' => $this->filled('brand') ? $this->string('brand')->toString() : null,
            'tag_id' => $this->filled('tag_id') ? $this->integer('tag_id') : null,
            ...($this->filled('category_ids') ? ['category_ids' => array_values(array_map(intval(...), $this->validated('category_ids')))] : []),
            ...($this->filled('min_price') ? ['min_price' => $this->string('min_price')->toString()] : []),
            ...($this->filled('max_price') ? ['max_price' => $this->string('max_price')->toString()] : []),
            ...($this->filled('sort') ? ['sort' => $this->string('sort')->toString()] : []),
        ];
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
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->where('is_active', true),
            ],
            'category_ids' => ['nullable', 'array', 'max:50', 'prohibits:category_id'],
            'category_ids.*' => ['required', 'integer', 'distinct', Rule::exists(Category::class, 'id')->where('is_active', true)],
            'min_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'max_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99', Rule::when($this->filled('min_price'), 'gte:min_price')],
            'sort' => ['nullable', Rule::in(['featured', 'price_asc', 'price_desc'])],
            'brand' => ['nullable', 'string', 'max:255'],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the validation messages for catalog filters.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Search must be text.',
            'q.max' => 'Search may not be longer than 255 characters.',
            'category_id.integer' => 'Select a valid category.',
            'category_id.exists' => 'Select an available category.',
            'brand.string' => 'Select a valid brand.',
            'brand.max' => 'Select a valid brand.',
            'tag_id.integer' => 'Select a valid tag.',
            'tag_id.exists' => 'Select a valid tag.',
            'page.integer' => 'The catalog page must be a whole number.',
            'page.min' => 'The catalog page must be at least 1.',
        ];
    }
}
