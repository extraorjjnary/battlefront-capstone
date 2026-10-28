<?php

namespace App\Http\Requests\PcBuilder;

use Illuminate\Foundation\Http\FormRequest;

class SavePcBuildRequest extends FormRequest
{
    /** @return array{name: string, mode: string, budget: string|null, edit_version?: int, components: list<array{key: string, product_id: int, quantity: int, configuration: array<string, mixed>}>} */
    public function buildData(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'mode' => $this->string('mode')->toString(),
            'budget' => $this->filled('budget') ? $this->string('budget')->toString() : null,
            'components' => array_values(array_map(fn (array $component): array => [
                'key' => $component['key'],
                'product_id' => (int) $component['product_id'],
                'quantity' => (int) $component['quantity'],
                'configuration' => $component['configuration'] ?? [],
            ], $this->validated('components'))),
        ];
        if (array_key_exists('edit_version', $this->validated())) {
            $data['edit_version'] = $this->integer('edit_version');
        }

        return $data;
    }

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
