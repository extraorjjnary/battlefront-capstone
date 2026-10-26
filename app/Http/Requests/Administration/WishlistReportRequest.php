<?php

namespace App\Http\Requests\Administration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WishlistReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reports.view') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'period' => ['nullable', 'string', Rule::in(['day', 'week', 'month'])],
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            'stock' => ['nullable', 'string', Rule::in(['all', 'in_stock', 'low_stock', 'out_of_stock', 'unavailable'])],
            'sort' => ['nullable', 'string', Rule::in(['customers', 'name', 'price', 'stock', 'interest'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'minimum_saves' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'minimum_net_additions' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'minimum_increase_percent' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $today = CarbonImmutable::now(config('battlefront.timezone'))->startOfDay();
            $end = $this->filled('to')
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to'), config('battlefront.timezone'))
                : $today;
            $start = $this->filled('from')
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'), config('battlefront.timezone'))
                : $end->subDays(29);

            if ($start->diffInDays($end) > 364) {
                $validator->errors()->add('to', 'Select a date range no longer than 365 days.');
            }
        });
    }

    /** @return array{from: string, to: string, period: string, q: string, category_id: ?int, stock: string, sort: string, direction: string, minimum_saves: int, minimum_net_additions: int, minimum_increase_percent: int} */
    public function filters(): array
    {
        $today = CarbonImmutable::now(config('battlefront.timezone'))->startOfDay();
        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to', $today->toDateString()), config('battlefront.timezone'));
        $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from', $to->subDays(29)->toDateString()), config('battlefront.timezone'));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => (string) ($this->input('period') ?: 'day'),
            'q' => trim((string) $this->input('q', '')),
            'category_id' => $this->integer('category_id') ?: null,
            'stock' => (string) ($this->input('stock') ?: 'all'),
            'sort' => (string) ($this->input('sort') ?: 'customers'),
            'direction' => (string) ($this->input('direction') ?: 'desc'),
            'minimum_saves' => $this->filled('minimum_saves') ? $this->integer('minimum_saves') : 3,
            'minimum_net_additions' => $this->filled('minimum_net_additions') ? $this->integer('minimum_net_additions') : 2,
            'minimum_increase_percent' => $this->filled('minimum_increase_percent') ? $this->integer('minimum_increase_percent') : 20,
        ];
    }
}
