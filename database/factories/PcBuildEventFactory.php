<?php

namespace Database\Factories;

use App\Models\PcBuild;
use App\Models\PcBuildEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcBuildEvent>
 */
class PcBuildEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_uuid' => (string) Str::uuid(),
            'pc_build_id' => PcBuild::factory(),
            'user_id' => User::factory()->customer(),
            'event_type' => 'reviewed',
            'configuration_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
