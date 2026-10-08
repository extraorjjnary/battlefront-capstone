<?php

namespace Database\Factories;

use App\Models\CustomerSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerSearch>
 */
class CustomerSearchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'query' => fake()->words(2, true),
            'expires_at' => now()->addDays(90),
        ];
    }
}
