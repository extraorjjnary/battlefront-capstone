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
            'package_count' => ['sometimes', 'required', 'integer', 'between:1,99'],
            'package_weight_kg' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999.999'],
            'package_length_cm' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999.99'],
            'package_width_cm' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999.99'],
            'package_height_cm' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999.99'],
            'declared_value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'handoff_mode' => ['sometimes', 'required', 'in:rider_pickup,branch_dropoff'],
            'actual_carrier_cost' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'carrier_cost_reference' => ['sometimes', 'required', 'string', 'max:100'],
            'carrier_cost_confirmed' => ['sometimes', 'accepted'],
            'shipment_exception_code' => ['sometimes', 'nullable', 'in:booking_failed,pickup_missed,delivery_attempt_failed,damaged,lost,return_to_sender,other'],
            'shipment_exception_note' => ['sometimes', 'nullable', 'required_with:shipment_exception_code', 'string', 'max:2000'],
            'shipment_exception_resolved' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $packageFields = ['package_count', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'declared_value', 'handoff_mode'];
            $hasPackageFields = collect($packageFields)->contains(fn (string $field): bool => $this->exists($field));

            if ($hasPackageFields) {
                foreach ($packageFields as $field) {
                    if (! $this->exists($field)) {
                        $validator->errors()->add($field, 'Complete all package details together.');
                    }
                }
            }

            if ($this->exists('actual_carrier_cost') !== $this->exists('carrier_cost_reference')) {
                $validator->errors()->add('actual_carrier_cost', 'Provide the carrier cost and its receipt or reference together.');
            }

            if (! $hasPackageFields && ! $this->exists('actual_carrier_cost')
                && ! $this->filled('shipment_exception_code') && ! $this->boolean('shipment_exception_resolved')) {
                $validator->errors()->add('shipment', 'Submit package details, carrier cost, or a shipment issue to update.');
            }

            if ($this->filled('actual_carrier_cost') && ! $this->boolean('carrier_cost_confirmed')) {
                $validator->errors()->add('carrier_cost_confirmed', 'Confirm the carrier cost against its receipt or LBC reference.');
            }
        }];
    }
}
