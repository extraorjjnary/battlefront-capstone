<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SavePushDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use-customer-cart') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:/^(ExpoPushToken|ExponentPushToken)\[[A-Za-z0-9_-]+\]$/'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ];
    }

    /** @return array{expo_push_token: string, platform: string} */
    public function deviceData(): array
    {
        return [
            'expo_push_token' => $this->string('expo_push_token')->toString(),
            'platform' => $this->string('platform')->toString(),
        ];
    }
}
