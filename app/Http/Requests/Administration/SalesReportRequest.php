<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesReportRequest extends FormRequest
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
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period' => ['nullable', 'string', Rule::in(['day', 'week', 'month'])],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the validation messages for sales-report filters.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required_with' => 'Select both a start and end date.',
            'from.date_format' => 'Enter the start date in YYYY-MM-DD format.',
            'to.required_with' => 'Select both a start and end date.',
            'to.date_format' => 'Enter the end date in YYYY-MM-DD format.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'period.in' => 'Select a valid reporting period.',
            'product_id.integer' => 'Select a valid product filter.',
            'product_id.exists' => 'The selected product is unavailable.',
            'category_id.integer' => 'Select a valid category filter.',
            'category_id.exists' => 'The selected category is unavailable.',
            'page.integer' => 'The product report page must be a whole number.',
            'page.min' => 'The product report page must be at least 1.',
        ];
    }
}
