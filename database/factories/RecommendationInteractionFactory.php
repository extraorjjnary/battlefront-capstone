<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\RecommendationInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecommendationInteraction>
 */
class RecommendationInteractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'product_id' => Product::factory(),
            'event_type' => 'impression',
            'placement' => 'home',
            'position' => 1,
            'reason_code' => 'popular_with_customers',
            'expires_at' => now()->addDays(90),
        ];
    }
}
