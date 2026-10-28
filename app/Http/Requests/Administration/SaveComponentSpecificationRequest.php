<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveComponentSpecificationRequest extends FormRequest
{
    /** @return array{product_id: int, component_type: string, manufacturer_part_number: string|null, hardware_revision: string|null, edit_version?: int, specifications: array<string, mixed>, evidence: list<array<string, string>>} */
    public function specificationData(): array
    {
        $data = [
            'product_id' => $this->integer('product_id'),
            'component_type' => $this->string('component_type')->toString(),
            'manufacturer_part_number' => $this->filled('manufacturer_part_number') ? $this->string('manufacturer_part_number')->toString() : null,
            'hardware_revision' => $this->filled('hardware_revision') ? $this->string('hardware_revision')->toString() : null,
            'specifications' => $this->validated('specifications'),
            'evidence' => array_values($this->validated('evidence')),
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'component_type' => ['required', Rule::in([
                'processor', 'motherboard', 'memory', 'storage', 'power_supply', 'case',
                'graphics_card', 'cooler', 'fan', 'network_adapter', 'monitor', 'keyboard',
                'mouse', 'headset', 'speakers', 'webcam', 'ups_avr',
            ])],
            'manufacturer_part_number' => ['nullable', 'string', 'max:128'],
            'hardware_revision' => ['nullable', 'string', 'max:80'],
            'edit_version' => ['nullable', 'integer', 'min:1'],
            'specifications' => ['required', 'array', 'min:1'],
            'evidence' => ['required', 'array', 'min:1', 'max:20'],
            'evidence.*.url' => ['required', 'url', 'max:2048'],
            'evidence.*.title' => ['required', 'string', 'max:255'],
            'evidence.*.section' => ['required', 'string', 'max:255'],
            'evidence.*.verified_at' => ['required', 'date'],
            'evidence.*.variant' => ['required', 'string', 'max:128'],
        ];
    }
}
