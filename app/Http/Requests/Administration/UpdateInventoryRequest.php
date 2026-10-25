<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryRequest extends FormRequest
{
    /**
     * Determine whether the user may update inventory.
     */
    public function authorize(): bool
    {
        return $this->user()?->administrationRoleCode() === 'staff'
            ? $this->user()->can('inventory.request')
            : ($this->user()?->can('inventory.adjust') ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $direct = $this->user()->can('inventory.adjust');
        $count = $this->routeIs('administration.inventory.count');

        return [
            'quantity' => ['bail', 'required', 'integer', 'min:0', 'max:4294967295'],
            'reorder_level' => ['bail', Rule::excludeIf($count), 'required', 'integer', 'min:0', 'max:4294967295'],
            'observed_quantity' => [Rule::requiredIf($count || ($direct && $this->routeIs('administration.inventory.update'))), 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'stock_adjustment_confirmed' => [Rule::excludeIf(! $direct), 'required', 'accepted'],
            'reason' => [Rule::requiredIf($direct && $this->input('reason_code') === 'theft_loss'), 'nullable', 'string', 'min:5', 'max:2000'],
            'reason_code' => ['required', Rule::in(['damage', 'theft_loss', 'found_stock', 'count_error', 'receiving_error', 'unit_of_measure_error', 'other'])],
        ];
    }

    /**
     * Get the validation messages for inventory values.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Enter the current quantity.',
            'quantity.integer' => 'Quantity must be a whole number.',
            'quantity.min' => 'Quantity must be zero or greater.',
            'quantity.max' => 'Quantity is too large.',
            'reorder_level.required' => 'Enter the reorder level.',
            'reorder_level.integer' => 'Reorder level must be a whole number.',
            'reorder_level.min' => 'Reorder level must be zero or greater.',
            'reorder_level.max' => 'Reorder level is too large.',
        ];
    }
}
