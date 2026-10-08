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
        ];
    }
}
