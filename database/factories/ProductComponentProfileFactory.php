<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductComponentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductComponentProfile>
 */
class ProductComponentProfileFactory extends Factory
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
            'component_type' => 'processor',
            'manufacturer_part_number' => fake()->bothify('CPU-####-????'),
            'hardware_revision' => null,
            'current_revision' => null,
            'review_status' => 'draft',
            'edit_version' => 1,
        ];
    }
}
