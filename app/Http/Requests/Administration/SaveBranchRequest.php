<?php

namespace App\Http\Requests\Administration;

use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'city'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::squish($this->input($field))]);
            }
        }

        if (! $this->exists('is_published')) {
            $branch = $this->route('branch');
            $this->merge(['is_published' => $branch instanceof Branch ? $branch->is_published : false]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $branch = $this->route('branch');
        $uniqueCity = Rule::unique(Branch::class, 'city');

        if ($branch instanceof Branch) {
            $uniqueCity->ignore($branch);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255', $uniqueCity],
            'address' => [Rule::requiredIf($this->requiresPublishedDetail('address')), 'nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 ().-]*$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'operating_hours' => [Rule::requiredIf($this->requiresPublishedDetail('operating_hours')), 'nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'is_published' => ['required', 'boolean'],
            'id' => ['prohibited'],
            'seed_key' => ['prohibited'],
            'is_operational' => ['prohibited'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $branch = $this->route('branch');

            if ($branch instanceof Branch && $branch->is_operational) {
                if ($this->input('city') !== $branch->city) {
                    $validator->errors()->add('city', 'The operational branch must remain in Sagay City.');
                }

                if (! $this->boolean('is_published')) {
                    $validator->errors()->add('is_published', 'Sagay City must remain published.');
                }
            } elseif (is_string($this->input('city'))
                && Str::lower($this->input('city')) === Str::lower(config('battlefront.operational_branch_city'))) {
                $validator->errors()->add('city', 'Sagay City is reserved for the existing operational branch.');
            }
        }];
    }

    private function requiresPublishedDetail(string $field): bool
    {
        $branch = $this->route('branch');

        return $this->boolean('is_published') && (
            ! $branch instanceof Branch
            || ! $branch->is_published
            || filled($branch->{$field})
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'city.unique' => 'A branch in this city already exists.',
            'address.required' => 'Enter a complete address before publishing.',
            'operating_hours.required' => 'Enter operating hours before publishing.',
            'contact_number.regex' => 'Enter a valid contact number using digits, spaces, +, parentheses, or hyphens.',
            'latitude.required_with' => 'Enter both latitude and longitude, or leave both blank.',
            'longitude.required_with' => 'Enter both latitude and longitude, or leave both blank.',
        ];
    }
}
