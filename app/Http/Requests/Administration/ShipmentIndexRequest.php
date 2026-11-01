<?php

namespace App\Http\Requests\Administration;

use App\Enums\ShipmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShipmentIndexRequest extends FormRequest
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
        return self::filterRules($this->filled('from'));
    }

    /** @return array<string, array<mixed>> */
    public static function filterRules(bool $hasFrom): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'shipment_status' => ['nullable', Rule::in(['active', 'all', ...array_column(ShipmentStatus::cases(), 'value')])],
            'reconciliation' => ['nullable', Rule::in(['all', 'confirmed', 'unconfirmed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($hasFrom ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalizeFilters(array $filters): array
    {
        $search = trim($filters['q'] ?? '');

        return [
            'q' => $search === '' ? null : $search,
            'shipment_status' => $filters['shipment_status'] ?? 'active',
            'reconciliation' => $filters['reconciliation'] ?? 'all',
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ];
    }
}
