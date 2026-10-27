<?php

namespace App\Http\Requests\PcBuilder;

use Illuminate\Foundation\Http\FormRequest;

class SavePcBuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use-customer-cart') ?? false;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', 'in:simple,advanced'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'edit_version' => ['sometimes', 'integer', 'min:1'],
            'components' => ['present', 'array', 'max:32'],
            'components.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'components.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'components.*.configuration' => ['sometimes', 'array'],
            'components.*.configuration.*' => ['string', 'max:80'],
        ];
    }
}
