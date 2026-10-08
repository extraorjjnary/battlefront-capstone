<?php

namespace Database\Factories;

use App\Models\CustomerProductView;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProductView>
 */
class CustomerProductViewFactory extends Factory
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
            'product_id' => Product::factory(),
            'expires_at' => now()->addDays(90),
        ];
    }
}
