<?php

namespace App\Http\Requests\Administration;

use App\Enums\ShipmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', Rule::enum(ShipmentStatus::class)],
            'tracking_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'handoff' => ['sometimes', 'missing_unless:status,handed_to_lbc', 'array:package_count,package_weight_kg,package_length_cm,package_width_cm,package_height_cm,handoff_mode,actual_carrier_cost,carrier_cost_reference,carrier_cost_confirmed'],
            'declared_value' => ['missing'],
            'actual_carrier_cost' => ['missing'],
        ];
    }
}
