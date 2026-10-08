<?php

namespace App\Http\Requests\Administration;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Select a product.',
            'product_id.integer' => 'Select a valid product.',
            'product_id.exists' => 'The selected product no longer exists.',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), ['product_id', '_token']) as $field) {
                    $validator->errors()->add((string) $field, 'Select a product only. Forecast method and dates are set automatically.');
                }
            },
        ];
    }
}
