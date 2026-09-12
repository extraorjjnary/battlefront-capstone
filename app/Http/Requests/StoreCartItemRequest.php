<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCartItemRequest extends FormRequest
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
            'product_id' => ['bail', 'required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['bail', 'required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * Get the validation messages for adding a cart item.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Select a product to add.',
            'product_id.integer' => 'Select a valid product.',
            'product_id.exists' => 'Select an available product.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.integer' => 'Quantity must be a whole number.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity is too large.',
        ];
    }
}
