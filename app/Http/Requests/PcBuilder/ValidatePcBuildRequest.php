<?php

namespace App\Http\Requests\PcBuilder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ValidatePcBuildRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'components' => ['present', 'array', 'max:32'],
            'components.*.key' => ['required', 'string', 'max:64'],
            'components.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'components.*.configuration' => ['sometimes', 'array'],
            'components.*.configuration.*' => ['string', 'max:80'],
        ];
    }
}
