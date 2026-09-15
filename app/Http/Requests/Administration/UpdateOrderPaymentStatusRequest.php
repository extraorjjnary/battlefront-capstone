<?php

namespace App\Http\Requests\Administration;

use App\Enums\PaymentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderPaymentStatusRequest extends FormRequest
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
            'payment_status' => [
                'bail',
                'required',
                Rule::enum(PaymentStatus::class)->only([
                    PaymentStatus::Verified,
                    PaymentStatus::Rejected,
                ]),
            ],
            'manual_verification_confirmed' => [
                Rule::excludeIf($this->input('payment_status') !== PaymentStatus::Verified->value),
                'required',
                'accepted',
            ],
        ];
    }

    /**
     * Get the validation messages for manual payment decisions.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_status.required' => 'Select a payment decision.',
            'payment_status.enum' => 'Select verified or rejected for the payment decision.',
            'manual_verification_confirmed.required' => 'Confirm the manual payment check before marking this payment as verified.',
            'manual_verification_confirmed.accepted' => 'Confirm the manual payment check before marking this payment as verified.',
        ];
    }
}
