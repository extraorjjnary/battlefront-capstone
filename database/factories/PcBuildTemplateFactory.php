<?php

namespace Database\Factories;

use App\Models\PcBuildTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcBuildTemplate>
 */
class PcBuildTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'budget' => fake()->randomFloat(2, 20000, 100000),
            'is_active' => false,
            'edit_version' => 1,
        ];
    }
}
