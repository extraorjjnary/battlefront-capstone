<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePcBuildTemplateRequest extends FormRequest
{
    /** @return array{name: string, description: string|null, budget: string|null, is_active: bool, edit_version?: int, components: list<array{key: string, product_id: int, quantity: int, configuration: array<string, mixed>}>} */
    public function templateData(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'budget' => $this->filled('budget') ? $this->string('budget')->toString() : null,
            'is_active' => $this->boolean('is_active'),
            'components' => array_values(array_map(fn (array $component): array => [
                'key' => $component['key'],
                'product_id' => (int) $component['product_id'],
                'quantity' => (int) $component['quantity'],
                'configuration' => $component['configuration'] ?? [],
            ], $this->validated('components'))),
        ];
        if ($this->filled('edit_version')) {
            $data['edit_version'] = $this->integer('edit_version');
        }

        return $data;
    }

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
