<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);
        $rules['default_delivery_address'][] = Rule::excludeIf(
            ! $this->user()->can('use-customer-cart'),
        );
        $rules['search_recommendations_enabled'] = [
            Rule::excludeIf(! $this->user()->can('use-customer-cart')),
            'sometimes',
            'boolean',
        ];
        $rules['product_view_recommendations_enabled'] = [
            Rule::excludeIf(! $this->user()->can('use-customer-cart')),
            'sometimes',
            'boolean',
        ];

        return $rules;
    }
}
