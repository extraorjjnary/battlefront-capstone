<?php

namespace Database\Factories;

use App\Models\CustomerReturnRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReturnRequest>
 */
class CustomerReturnRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'order_id' => fn (array $attributes) => OrderItem::query()->whereKey($attributes['order_item_id'])->firstOrFail()->order_id,
            'user_id' => fn (array $attributes) => Order::query()->whereKey($attributes['order_id'])->firstOrFail()->user_id,
            'quantity' => 1,
            'reason_code' => 'defective',
            'reason' => null,
        ];
    }
}
