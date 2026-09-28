<?php

namespace App\Http\Requests;

use App\Enums\RecommendationIntendedUse;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecommendationInputRequest extends FormRequest
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
            'budget' => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'intended_use' => ['required', Rule::enum(RecommendationIntendedUse::class)],
            'preferred_brand' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->where('is_active', true),
            ],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['bail', 'integer', 'distinct', Rule::exists(Tag::class, 'id')],
        ];
    }

    /**
     * Get validation messages for recommendation criteria.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget.required' => 'Enter a budget.',
            'budget.numeric' => 'Budget must be a number.',
            'budget.decimal' => 'Budget may have no more than two decimal places.',
            'budget.min' => 'Budget must be at least PHP 0.01.',
            'budget.max' => 'Budget may not exceed PHP 9,999,999,999.99.',
            'intended_use.required' => 'Select an intended use.',
            'intended_use.enum' => 'Select a valid intended use.',
            'preferred_brand.string' => 'Preferred brand must be text.',
            'preferred_brand.max' => 'Preferred brand may not be longer than 255 characters.',
            'category_id.integer' => 'Select a valid category.',
            'category_id.exists' => 'Select an available category.',
            'tag_ids.array' => 'Select product tags as a list.',
            'tag_ids.*.integer' => 'Select valid product tags.',
            'tag_ids.*.distinct' => 'Each tag may only be selected once.',
            'tag_ids.*.exists' => 'Select valid product tags.',
        ];
    }

    /**
     * Get reusable criteria from validated customer input. Availability comes from live inventory.
     *
     * @return array{
     *     budget: string,
     *     intended_use: RecommendationIntendedUse,
     *     preferred_brand: string|null,
     *     category_id: int|null,
     *     tag_ids: list<int>
     * }
     */
    public function validatedCriteria(): array
    {
        $validated = $this->validated();
        $preferredBrand = trim((string) ($validated['preferred_brand'] ?? ''));

        return [
            'budget' => (string) $validated['budget'],
            'intended_use' => RecommendationIntendedUse::from((string) $validated['intended_use']),
            'preferred_brand' => $preferredBrand !== '' ? $preferredBrand : null,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'tag_ids' => array_values(array_map(
                static fn (mixed $tagId): int => (int) $tagId,
                $validated['tag_ids'] ?? [],
            )),
        ];
    }
}
