<?php

namespace Database\Factories;

use App\Models\PcBuild;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcBuild>
 */
class PcBuildFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->customer(),
            'name' => fake()->words(3, true),
            'mode' => 'simple',
            'budget' => null,
            'edit_version' => 1,
        ];
    }
}
