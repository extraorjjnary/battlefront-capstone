<?php

namespace App\Http\Requests\PcBuilder;

use App\Services\PcBuilder\ComponentTypes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ComponentOptionsRequest extends ValidatePcBuildRequest
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
            ...parent::rules(),
            'components.*' => ['array:key,product_id,quantity,configuration'],
            'component_type' => ['required', Rule::in(ComponentTypes::all())],
            'selection_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'candidate_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'candidate_configuration' => ['sometimes', 'nullable', 'array'],
            'candidate_configuration.*' => ['string', 'max:80'],
            'remove_keys' => ['sometimes', 'array', 'max:32'],
            'remove_keys.*' => ['required', 'string', 'max:64', 'distinct'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['name', 'price', 'price_desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
