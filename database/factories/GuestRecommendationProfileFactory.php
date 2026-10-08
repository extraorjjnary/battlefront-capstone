<?php

namespace Database\Factories;

use App\Models\GuestRecommendationProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestRecommendationProfile>
 */
class GuestRecommendationProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addDays(90),
        ];
    }
}
