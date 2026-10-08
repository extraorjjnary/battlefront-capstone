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
        $filters = [
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'brand' => $this->filled('brand') ? $this->string('brand')->toString() : null,
            'tag_id' => $this->filled('tag_id') ? $this->integer('tag_id') : null,
        ];

        if (! $this->routeIs('api.v1.products.index')) {
            return $filters;
        }

        $validated = $this->validated();
        $categoryIds = $validated['category_ids'] ?? [];

        if (is_array($categoryIds) && $categoryIds !== []) {
            $filters['category_ids'] = array_values(array_map(
                static fn (mixed $categoryId): int => (int) $categoryId,
                $categoryIds,
            ));
        }

        foreach (['min_price', 'max_price', 'sort'] as $filter) {
            if (isset($validated[$filter])) {
                $filters[$filter] = (string) $validated[$filter];
            }
        }

        return $filters;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->where('is_active', true),
            ],
            'brand' => ['nullable', 'string', 'max:255'],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        if (! $this->routeIs('api.v1.products.index')) {
            return $rules;
        }

        $rules['category_ids'] = ['bail', 'nullable', 'array', 'max:50', 'prohibits:category_id'];
        $categoryIds = $this->input('category_ids');

        if (is_array($categoryIds) && count($categoryIds) <= 50) {
            $rules['category_ids.*'] = [
                'bail', 'required', 'integer', 'distinct',
                Rule::exists(Category::class, 'id')->where('is_active', true),
            ];
        }

        $rules['min_price'] = ['bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'];
        $rules['max_price'] = [
            'bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99',
            Rule::when($this->filled('min_price'), 'gte:min_price'),
        ];
        $rules['sort'] = ['nullable', Rule::in(['featured', 'price_asc', 'price_desc'])];

        return $rules;
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
