<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Concerns\ValidatesDeliveryGeography;
use App\Services\Order\PsgcDeliveryLocations;
use App\Support\PhilippineMobileNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;
    use ValidatesDeliveryGeography;

    protected function prepareForValidation(): void
    {
        if ($this->filled('default_delivery_city_code') && ! $this->has('default_delivery_destination')) {
            $this->merge(['default_delivery_destination' => $this->input('default_delivery_city_code')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);
        $rules['email'] = [
            'bail', 'sometimes', 'required', 'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== $this->user()->email) {
                    $fail('Your account email address cannot be changed.');
                }
            },
        ];
        $canManageAddress = $this->user()->can('use-customer-cart');
        $addressAction = $this->addressAction();
        $rules['default_delivery_address'][] = Rule::excludeIf(
            ! $canManageAddress || $addressAction !== 'legacy',
        );
        $rules['default_delivery_address_action'] = [
            Rule::excludeIf(! $canManageAddress),
            'bail', 'sometimes', 'required', 'string', Rule::in(['keep', 'save', 'clear']),
        ];
        $rules['default_delivery_contact_number'] = [
            Rule::excludeIf(! $canManageAddress),
            'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && ! PhilippineMobileNumber::isValid($value)) {
                    $fail('Enter a valid Philippine mobile number.');
                }
            },
        ];
        $rules['default_delivery_destination'] = [
            Rule::excludeIf(! $canManageAddress || $addressAction !== 'save'),
            'bail', 'required', 'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || (! preg_match('/\A[0-9]{10}\z/', $value) && ! in_array($value, PsgcDeliveryLocations::LEGACY_DESTINATIONS, true))) {
                    $fail('Select a supported delivery destination.');
                }
            },
        ];
        $rules['default_delivery_barangay_code'] = [
            Rule::excludeIf(! $canManageAddress || $addressAction !== 'save'),
            'bail', 'required', 'string', 'regex:/\A[0-9]{10}\z/',
        ];
        $rules['default_delivery_address_details'] = [
            Rule::excludeIf(! $canManageAddress || $addressAction !== 'save'),
            'bail', 'required', 'string', 'max:255',
        ];
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
        $rules['personalized_recommendations_enabled'] = [
            Rule::excludeIf(! $this->user()->can('use-customer-cart')),
            'sometimes',
            'boolean',
        ];

        return [...$rules, ...$this->geographyRules('default_delivery', $canManageAddress && $addressAction === 'save' && $this->usesNationwideAddress('default_delivery'))];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()->can('use-customer-cart') && $this->addressAction() === 'save') {
                    $this->checkGeography($validator, 'default_delivery');
                }
            },
            function (Validator $validator): void {
                if (! $this->user()->can('use-customer-cart') || $this->addressAction() !== 'save'
                    || $validator->errors()->hasAny([
                        'default_delivery_address_action', 'default_delivery_destination',
                        'default_delivery_barangay_code', 'default_delivery_address_details', 'default_delivery_city_code', 'default_delivery_region_code', 'default_delivery_province_code',
                    ])) {
                    return;
                }

                foreach (app(PsgcDeliveryLocations::class)->addressErrors(
                    $this->string('default_delivery_destination')->toString(),
                    $this->string('default_delivery_barangay_code')->toString(),
                    $this->string('default_delivery_address_details')->toString(),
                ) as $field => $message) {
                    $validator->errors()->add('default_delivery_'.$field, $message);
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function profileData(): array
    {
        $data = $this->safe()->except([
            'default_delivery_address_action', 'default_delivery_destination',
            'default_delivery_barangay_code', 'default_delivery_address_details',
            'default_delivery_city_code', 'default_delivery_region_code', 'default_delivery_province_code',
        ]);

        if (! $this->user()->can('use-customer-cart')) {
            return $data;
        }

        $locations = app(PsgcDeliveryLocations::class);

        if ($this->addressAction() === 'save') {
            $destination = $this->string('default_delivery_destination')->toString();
            $barangayCode = $this->string('default_delivery_barangay_code')->toString();
            $details = $this->string('default_delivery_address_details')->toString();
            $data['default_delivery_address'] = $locations->formatAddress($destination, $barangayCode, $details);
            $data['default_delivery_address_components'] = $locations->components($destination, $barangayCode, $details);
        } elseif ($this->addressAction() === 'clear') {
            $data['default_delivery_address'] = null;
            $data['default_delivery_address_components'] = null;
        } elseif ($this->addressAction() === 'legacy') {
            $prefill = $locations->prefill($this->validated('default_delivery_address'));
            $data['default_delivery_address_components'] = $prefill['delivery_barangay_code'] !== ''
                && $locations->addressErrors($prefill['delivery_destination'], $prefill['delivery_barangay_code'], $prefill['delivery_address_details']) === []
                ? $locations->components($prefill['delivery_destination'], $prefill['delivery_barangay_code'], $prefill['delivery_address_details'])
                : null;
        }

        return $data;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Your account email address cannot be changed.',
            'email.string' => 'Your account email address cannot be changed.',
            'default_delivery_address_action.*' => 'Choose whether to keep, replace, or clear the saved delivery address.',
            'default_delivery_destination.required' => 'Select a supported delivery destination.',
            'default_delivery_destination.string' => 'Select one supported delivery destination.',
            'default_delivery_destination.in' => 'Select a supported delivery destination.',
            'default_delivery_barangay_code.required' => 'Select a barangay.',
            'default_delivery_barangay_code.string' => 'Select a valid barangay.',
            'default_delivery_barangay_code.regex' => 'Select a valid barangay.',
            'default_delivery_address_details.required' => 'Enter the house, building, street, or landmark details.',
            'default_delivery_address_details.string' => 'Enter the house, building, street, or landmark details.',
            'default_delivery_address_details.max' => 'Delivery address details may not exceed 255 characters.',
        ];
    }

    private function addressAction(): string
    {
        if ($this->has('default_delivery_address_action')) {
            $action = $this->input('default_delivery_address_action');

            return is_string($action) ? $action : '';
        }

        if ($this->hasAny(['default_delivery_destination', 'default_delivery_barangay_code', 'default_delivery_address_details'])) {
            return 'save';
        }

        if ($this->has('default_delivery_address')) {
            return $this->routeIs('api.v1.profile.update') ? 'legacy'
                : ($this->filled('default_delivery_address') ? 'save' : 'clear');
        }

        return 'keep';
    }
}
