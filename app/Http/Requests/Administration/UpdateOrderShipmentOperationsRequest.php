<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOrderShipmentOperationsRequest extends FormRequest
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
            'package_count' => ['missing'],
            'package_weight_kg' => ['missing'],
            'package_length_cm' => ['missing'],
            'package_width_cm' => ['missing'],
            'package_height_cm' => ['missing'],
            'declared_value' => ['missing'],
            'handoff_mode' => ['missing'],
            'actual_carrier_cost' => ['missing'],
            'carrier_cost_reference' => ['missing'],
            'carrier_cost_confirmed' => ['missing'],
            'shipment_exception_code' => ['sometimes', 'nullable', 'in:booking_failed,pickup_missed,delivery_attempt_failed,damaged,lost,return_to_sender,other'],
            'shipment_exception_note' => ['sometimes', 'nullable', 'required_with:shipment_exception_code', 'string', 'max:2000'],
            'shipment_exception_resolved' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('shipment_exception_code') && ! $this->boolean('shipment_exception_resolved')) {
                $validator->errors()->add('shipment', 'Submit a shipment issue or resolve the current issue to update.');
            }
        }];
    }
}
