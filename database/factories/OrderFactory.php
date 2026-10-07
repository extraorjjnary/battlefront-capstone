<?php

namespace Database\Factories;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShippingProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\DeliveryRules;
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
            'payment_rejection_reason' => null,
            'payment_rejection_note' => null,
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
     * Provide an explicit commercial snapshot without creating a shipment automatically.
     *
     * @param  numeric-string  $subtotal
     */
    public function withDeliverySnapshot(
        string $destination = 'Sagay City',
        ShippingProfile $profile = ShippingProfile::Standard,
        string $subtotal = '100.00',
    ): static {
        return $this->delivery()->paidWithGCash()->state(function (array $attributes) use ($destination, $profile, $subtotal): array {
            $quote = app(DeliveryRules::class)->quote(
                FulfillmentMethod::Delivery,
                $destination,
                [new Product(['shipping_profile' => $profile])],
            );

            return [
                'product_subtotal' => $subtotal,
                'total_amount' => bcadd($subtotal, $quote['delivery_fee'], 2),
                'delivery_destination' => $quote['destination'],
                'delivery_base_fee' => $quote['base_fee'],
                'shipping_profile' => $quote['shipping_profile'],
                'handling_surcharge' => $quote['handling_surcharge'],
                'delivery_fee' => $quote['delivery_fee'],
                'delivery_origin_city' => $quote['origin_city'],
                'delivery_is_demo' => $quote['is_demo'],
                'delivery_assumption_label' => $quote['assumption_label'],
            ];
        });
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

    /**
     * Indicate that submitted wallet evidence was rejected.
     */
    public function withRejectedPaymentProof(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_status' => PaymentStatus::Rejected->value,
            'payment_rejection_reason' => PaymentRejectionReason::TransactionUnverified->value,
            'payment_rejection_note' => null,
        ]);
    }
}
