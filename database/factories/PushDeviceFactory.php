<?php

namespace Database\Factories;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PushDevice> */
class PushDeviceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $token = 'ExpoPushToken['.Str::random(22).']';

        return [
            'user_id' => User::factory()->customer(),
            'device_id' => (string) Str::uuid(),
            'personal_access_token_id' => fn (array $attributes): int => User::query()->whereKey($attributes['user_id'])->firstOrFail()
                ->createToken('Test phone', ['*'], now()->addDays(30))->accessToken->id,
            'platform' => 'android',
            'expo_push_token' => $token,
            'token_hash' => hash('sha256', $token),
            'registration_version' => (string) Str::uuid(),
            'is_active' => true,
        ];
    }
}
