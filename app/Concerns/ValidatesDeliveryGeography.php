<?php

namespace App\Concerns;

use App\Services\Order\PsgcDeliveryLocations;
use DomainException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesDeliveryGeography
{
    private function usesNationwideAddress(string $prefix): bool
    {
        $destination = $this->input($prefix.'_destination');

        return $this->hasAny([$prefix.'_region_code', $prefix.'_province_code', $prefix.'_city_code'])
            || (is_string($destination) && preg_match('/\A[0-9]{10}\z/', $destination) === 1);
    }

    /** @return array<string, array<mixed>> */
    private function geographyRules(string $prefix, bool $active, bool $prohibited = false): array
    {
        $rules = [];
        foreach (['region_code', 'province_code', 'city_code'] as $field) {
            $rules[$prefix.'_'.$field] = ['bail', Rule::excludeIf(! $active && ! $prohibited),
                Rule::prohibitedIf($prohibited), Rule::requiredIf($active && $field !== 'province_code'),
                'nullable', 'string', 'regex:/\A[0-9]{10}\z/'];
        }

        return $rules;
    }

    private function checkGeography(Validator $validator, string $prefix): void
    {
        if (! $this->usesNationwideAddress($prefix) || $validator->errors()->hasAny([
            $prefix.'_region_code', $prefix.'_province_code', $prefix.'_city_code', $prefix.'_destination',
        ])) {
            return;
        }
        $locations = app(PsgcDeliveryLocations::class);
        $cityCode = $this->string($prefix.'_city_code')->toString();
        foreach ($locations->hierarchyErrors($this->string($prefix.'_region_code')->toString(), $this->input($prefix.'_province_code'), $cityCode) as $field => $message) {
            $validator->errors()->add($prefix.'_'.$field, $message);
        }
        try {
            $destination = $this->string($prefix.'_destination')->toString();
            $code = preg_match('/\A[0-9]{10}\z/', $destination) === 1 ? $destination : $locations->destination($destination)['code'];
            if ($code !== $cityCode) {
                $validator->errors()->add($prefix.'_destination', 'The destination must match the selected city.');
            }
        } catch (DomainException) {
            $validator->errors()->add($prefix.'_destination', 'Select a valid delivery destination.');
        }
    }
}
