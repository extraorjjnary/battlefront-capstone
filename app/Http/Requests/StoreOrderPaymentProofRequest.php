<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderPaymentProofRequest extends FormRequest
{
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
        return [
            'payment_proof' => [
                'bail',
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp',
                'max:5120',
            ],
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
            'payment_proof.required' => 'Upload a replacement payment proof.',
            'payment_proof.file' => 'The replacement proof must be an uploaded file.',
            'payment_proof.image' => 'The replacement proof must be an image.',
            'payment_proof.mimes' => 'The replacement proof must be a JPEG, PNG, or WebP image.',
            'payment_proof.extensions' => 'The replacement proof filename must end in JPG, JPEG, PNG, or WebP.',
            'payment_proof.max' => 'The replacement proof may not be larger than 5 MB.',
        ];
    }
}
