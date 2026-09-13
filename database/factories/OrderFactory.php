<?php

namespace Database\Factories;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->customer(),
            'recipient_name' => fake()->name(),
            'contact_number' => fake()->numerify('09## ### ####'),
            'fulfillment_method' => FulfillmentMethod::Pickup->value,
            'delivery_address' => null,
            'total_amount' => fake()->randomFloat(2, 0, 500000),
            'status' => OrderStatus::Pending->value,
            'payment_status' => PaymentStatus::Pending->value,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_proof_path' => null,
        ];
    }

    /**
     * Indicate that the order will be delivered.
     */
    public function delivery(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfillment_method' => FulfillmentMethod::Delivery->value,
            'delivery_address' => fake()->address(),
        ]);
    }

    /**
     * Indicate that the order uses GCash with submitted evidence.
     */
    public function paidWithGCash(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_method' => PaymentMethod::GCash->value,
            'payment_proof_path' => 'payment-proofs/'.fake()->uuid().'.jpg',
        ]);
    }

    /**
     * Indicate that the order uses Maya with submitted evidence.
     */
    public function paidWithMaya(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_method' => PaymentMethod::Maya->value,
            'payment_proof_path' => 'payment-proofs/'.fake()->uuid().'.jpg',
        ]);
    }
}
