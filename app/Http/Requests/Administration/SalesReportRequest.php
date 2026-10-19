<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesReportRequest extends FormRequest
{
    private const FILTER_FIELDS = ['from', 'to', 'period', 'product_id', 'category_id'];

    /**
     * Explicit filter URLs replace the selection; bare and page-only URLs restore it.
     *
     * @return array<string, mixed>
     */
    public function effectiveFilters(): array
    {
        if ($this->hasExplicitFilters()) {
            return $this->safe()->only(self::FILTER_FIELDS);
        }

        $saved = $this->session()->get($this->filterSessionKey());
        if ($saved === null) {
            return [];
        }

        if (is_array($saved)) {
            $saved = Arr::only($saved, self::FILTER_FIELDS);
            $validator = Validator::make($saved, Arr::only($this->rules(), self::FILTER_FIELDS));
            if ($validator->passes()) {
                return $validator->validated();
            }
        }

        if ($this->routeIs('administration.reports.sales')) {
            $this->clearSavedFilters();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function rememberFilters(array $filters): void
    {
        if ($this->hasExplicitFilters()) {
            $this->session()->put($this->filterSessionKey(), Arr::only($filters, self::FILTER_FIELDS));
        }
    }

    public function clearSavedFilters(): void
    {
        $this->session()->forget($this->filterSessionKey());
    }

    private function hasExplicitFilters(): bool
    {
        return array_any(self::FILTER_FIELDS, fn (string $field): bool => $this->query->has($field));
    }

    private function filterSessionKey(): string
    {
        return 'administration.sales_report_filters.'.$this->user()?->getAuthIdentifier();
    }

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
            'sale_page' => ['nullable', 'integer', 'min:1'],
            'refund_page' => ['nullable', 'integer', 'min:1'],
            'balance_page' => ['nullable', 'integer', 'min:1'],
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
