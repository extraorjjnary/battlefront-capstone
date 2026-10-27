<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePcBuildTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['required', 'boolean'],
            'edit_version' => ['nullable', 'integer', 'min:1'],
            'components' => ['required', 'array', 'min:1', 'max:30'],
            'components.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'components.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'components.*.configuration' => ['nullable', 'array'],
        ];
    }
}
