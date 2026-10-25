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
            'package_count' => ['required', 'integer', 'between:1,99'],
            'package_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999.999'],
            'package_length_cm' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'package_width_cm' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'package_height_cm' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'declared_value' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'handoff_mode' => ['required', 'in:rider_pickup,branch_dropoff'],
            'actual_carrier_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'carrier_cost_reference' => ['nullable', 'string', 'max:100', 'required_with:actual_carrier_cost'],
            'carrier_cost_confirmed' => ['sometimes', 'accepted'],
            'shipment_exception_code' => ['nullable', 'in:booking_failed,pickup_missed,delivery_attempt_failed,damaged,lost,return_to_sender,other'],
            'shipment_exception_note' => ['nullable', 'string', 'max:2000', 'required_with:shipment_exception_code'],
            'shipment_exception_resolved' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('actual_carrier_cost') && ! $this->boolean('carrier_cost_confirmed')) {
                $validator->errors()->add('carrier_cost_confirmed', 'Confirm the carrier cost against its receipt or LBC reference.');
            }
        }];
    }
}
