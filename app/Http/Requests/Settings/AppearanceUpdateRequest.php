<?php

namespace App\Http\Requests\Settings;

use App\Enums\AppearancePreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AppearanceUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appearance' => ['required', new Enum(AppearancePreference::class)],
        ];
    }

    /**
     * Get customer-facing validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'appearance.required' => 'Select an appearance preference.',
            'appearance.*' => 'Select light, dark, or system appearance.',
        ];
    }
}
