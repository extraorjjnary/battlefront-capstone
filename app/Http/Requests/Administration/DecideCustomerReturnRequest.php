<?php

namespace App\Http\Requests\Administration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'disposition' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', Rule::in(['restock', 'write_off', 'supplier_return', 'return_to_customer'])],
            'approved_amount' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['required_if:disposition,supplier_return', 'prohibited_if:decision,reject', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'inspection_confirmed' => ['sometimes', 'required_if:disposition,restock', 'accepted'],
            'resolution_type' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', Rule::in(['refund', 'replacement', 'repair', 'other'])],
            'resolution_reference' => ['prohibited_if:decision,reject', 'nullable', 'string', 'max:255'],
            'inspection_notes' => ['required_if:disposition,restock', 'prohibited_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
            'public_feedback' => ['nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
