<?php

namespace App\Support;

class PhilippineMobileNumber
{
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $digits = preg_replace('/[ -]/', '', $value);

        return is_string($digits) && (preg_match('/\A09[0-9]{9}\z/', $digits) === 1
            || preg_match('/\A\+639[0-9]{9}\z/', $digits) === 1);
    }

    public static function normalize(string $value): string
    {
        $digits = preg_replace('/[ -]/', '', $value);

        if (is_string($digits) && preg_match('/\A09([0-9]{9})\z/', $digits, $matches) === 1) {
            return '+639'.$matches[1];
        }

        return is_string($digits) ? $digits : $value;
    }
}
