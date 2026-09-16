<?php

namespace App\Http\Requests\Administration;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['active', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'fulfillment_method' => ['nullable', Rule::enum(FulfillmentMethod::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the validation messages for order-directory filters.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Search must be text.',
            'q.max' => 'Search may not be longer than 255 characters.',
            'status.in' => 'Select a valid order view.',
            'payment_status.enum' => 'Select a valid payment status.',
            'payment_method.enum' => 'Select a valid payment method.',
            'fulfillment_method.enum' => 'Select a valid fulfillment method.',
            'page.integer' => 'The order page must be a whole number.',
            'page.min' => 'The order page must be at least 1.',
        ];
    }
}
