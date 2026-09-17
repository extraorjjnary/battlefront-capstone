<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 0, 500000);

        return [
            'order_id' => Order::factory()->state([
                'total_amount' => $amount,
                'status' => OrderStatus::Completed->value,
                'payment_status' => PaymentStatus::Verified->value,
            ]),
            'amount' => $amount,
            'sale_date' => fake()->date(),
        ];
    }
}
