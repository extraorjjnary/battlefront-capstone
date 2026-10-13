<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateManualShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'arrangement_confirmed' => ['required', 'accepted'],
            'carrier' => ['bail', 'required', 'string', Rule::in(['lbc', 'LBC'])],
            'tracking_reference' => ['nullable', 'string', 'max:255'],
            'eta_min_days' => ['nullable', 'integer', 'min:0', 'max:3650', 'required_with:eta_max_days'],
            'eta_max_days' => ['nullable', 'integer', 'min:0', 'max:3650', 'gte:eta_min_days', 'required_with:eta_min_days'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'arrangement_confirmed.accepted' => 'Confirm that LBC has accepted the manual shipping arrangement.',
            'carrier.in' => 'Select LBC only after confirming that it has accepted this delivery.',
            'eta_min_days.required_with' => 'Enter both estimated delivery day values, or leave both blank when no estimate is available.',
            'eta_max_days.required_with' => 'Enter both estimated delivery day values, or leave both blank when no estimate is available.',
            'eta_max_days.gte' => 'The maximum estimated delivery days must be at least the minimum.',
        ];
    }
}
