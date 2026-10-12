<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmOrderPickupRequest extends FormRequest
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
            'payment_received_confirmed' => ['required', 'accepted'],
            'products_handed_over_confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_received_confirmed.required' => 'Confirm that cash was received or the physical card transaction succeeded.',
            'payment_received_confirmed.accepted' => 'Confirm that cash was received or the physical card transaction succeeded.',
            'products_handed_over_confirmed.required' => 'Confirm that the products were handed over to the customer.',
            'products_handed_over_confirmed.accepted' => 'Confirm that the products were handed over to the customer.',
        ];
    }
}
