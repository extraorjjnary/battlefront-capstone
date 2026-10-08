<?php

namespace Database\Factories;

use App\Models\Forecast;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Forecast>
 */
class ForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'method' => 'additive_holt_winters',
            'predicted_demand' => '0.00',
            'forecast_quarter' => '2026-Q4',
            'generated_at' => now(),
        ];
    }
}
