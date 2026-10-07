<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Services\Order\DeliveryRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class ValidateCheckoutRequest extends FormRequest
{
    /**
     * Return the validated checkout fields shared by web and API placement.
     *
     * @return array{recipient_name: string, contact_number: string, fulfillment_method: string, delivery_address: string|null, delivery_destination: string|null, payment_method: string}
     */
    public function checkoutData(): array
    {
        return [
            'recipient_name' => $this->string('recipient_name')->toString(),
            'contact_number' => $this->string('contact_number')->toString(),
            'fulfillment_method' => $this->string('fulfillment_method')->toString(),
            'delivery_address' => $this->filled('delivery_address')
                ? $this->string('delivery_address')->toString()
                : null,
            'delivery_destination' => $this->filled('delivery_destination')
                ? $this->string('delivery_destination')->toString()
                : null,
            'payment_method' => $this->string('payment_method')->toString(),
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('use-customer-cart') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requiresPaymentProof = in_array(
            $this->input('payment_method'),
            [PaymentMethod::GCash->value, PaymentMethod::Maya->value],
            strict: true,
        );

        return [
            'recipient_name' => ['bail', 'required', 'string', 'max:255'],
            'contact_number' => ['bail', 'required', 'string', 'max:20'],
            'fulfillment_method' => ['bail', 'required', new Enum(FulfillmentMethod::class)],
            'delivery_destination' => [
                'bail',
                Rule::requiredIf($this->input('fulfillment_method') === FulfillmentMethod::Delivery->value),
                Rule::prohibitedIf($this->input('fulfillment_method') === FulfillmentMethod::Pickup->value),
                'nullable',
                'string',
                Rule::in(array_keys(app(DeliveryRules::class)->destinations())),
            ],
            'delivery_address' => [
                'bail',
                Rule::requiredIf($this->input('fulfillment_method') === FulfillmentMethod::Delivery->value),
                Rule::prohibitedIf($this->input('fulfillment_method') === FulfillmentMethod::Pickup->value),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_method' => ['bail', 'required', new Enum(PaymentMethod::class)],
            'payment_proof' => [
                'bail',
                Rule::requiredIf($requiresPaymentProof),
                Rule::prohibitedIf(! $requiresPaymentProof),
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * Get the validation callbacks for cross-field checkout rules.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['fulfillment_method', 'payment_method'])) {
                    return;
                }

                $fulfillmentMethod = FulfillmentMethod::from($this->string('fulfillment_method')->toString());
                $paymentMethod = PaymentMethod::from($this->string('payment_method')->toString());

                if (! $paymentMethod->isAvailableFor($fulfillmentMethod)) {
                    $validator->errors()->add(
                        'payment_method',
                        'Delivery orders must be paid through GCash or Maya.',
                    );
                }
            },
        ];
    }

    /**
     * Get customer-facing validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Enter the recipient name.',
            'recipient_name.max' => 'The recipient name may not exceed 255 characters.',
            'contact_number.required' => 'Enter a contact number.',
            'contact_number.max' => 'The contact number may not exceed 20 characters.',
            'fulfillment_method.required' => 'Select pickup or delivery.',
            'fulfillment_method.*' => 'Select a valid fulfillment method.',
            'delivery_destination.required' => 'Select a supported delivery destination.',
            'delivery_destination.prohibited' => 'A delivery destination is not used for pickup orders.',
            'delivery_destination.string' => 'Select one supported delivery destination.',
            'delivery_destination.in' => 'Select a supported delivery destination.',
            'delivery_address.required' => 'Enter a delivery address.',
            'delivery_address.prohibited' => 'A delivery address is not used for pickup orders.',
            'delivery_address.max' => 'The delivery address may not exceed 255 characters.',
            'payment_method.required' => 'Select a payment method.',
            'payment_method.*' => 'Select a valid payment method.',
            'payment_proof.required' => 'Upload a screenshot or snapshot of your successful payment.',
            'payment_proof.prohibited' => 'Payment proof is not required for this payment method.',
            'payment_proof.file' => 'The payment proof must be an uploaded file.',
            'payment_proof.image' => 'The payment proof must be an image.',
            'payment_proof.mimes' => 'The payment proof must be a JPEG, PNG, or WebP image.',
            'payment_proof.extensions' => 'The payment proof filename must end in JPG, JPEG, PNG, or WebP.',
            'payment_proof.max' => 'The payment proof may not be larger than 5 MB.',
        ];
    }
}
