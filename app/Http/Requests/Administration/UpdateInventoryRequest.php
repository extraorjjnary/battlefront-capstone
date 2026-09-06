<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    /**
     * Determine whether the user may update inventory.
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
            'quantity' => ['bail', 'required', 'integer', 'min:0', 'max:4294967295'],
            'reorder_level' => ['bail', 'required', 'integer', 'min:0', 'max:4294967295'],
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
