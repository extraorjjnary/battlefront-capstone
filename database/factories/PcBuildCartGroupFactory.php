<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\PcBuildCartGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcBuildCartGroup>
 */
class PcBuildCartGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'name' => fake()->words(2, true),
            'mode' => 'simple',
            'ruleset_version' => 1,
            'configuration_snapshot' => [],
        ];
    }
}
