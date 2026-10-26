<?php

namespace Database\Factories;

use App\Enums\FulfillmentMethod;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\Order\DeliveryRules;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * @extends Factory<Shipment>
 *
 * @phpstan-import-type DeliveryQuote from DeliveryRules
 */
class ShipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->withDeliverySnapshot(),
            'carrier' => Config::string('battlefront.delivery.carrier'),
            'status' => ShipmentStatus::AwaitingPreparation,
            'preparation_days' => fn (array $attributes): int => $this->quoteForOrder($attributes['order_id'])['preparation_days'],
            'transit_min_days' => fn (array $attributes): int => $this->quoteForOrder($attributes['order_id'])['transit_min_days'],
            'transit_max_days' => fn (array $attributes): int => $this->quoteForOrder($attributes['order_id'])['transit_max_days'],
            'eta_min_days' => fn (array $attributes): int => $this->quoteForOrder($attributes['order_id'])['eta_min_days'],
            'eta_max_days' => fn (array $attributes): int => $this->quoteForOrder($attributes['order_id'])['eta_max_days'],
            'tracking_reference' => null,
            'handed_to_carrier_at' => null,
            'delivered_at' => null,
        ];
    }

    /**
     * Supply the package snapshot required before a fixture can reach carrier handoff.
     */
    public function withHandoffDetails(string $declaredValue): static
    {
        return $this->state(fn (array $attributes): array => [
            'package_count' => 1,
            'package_weight_kg' => '1.000',
            'package_length_cm' => '10.00',
            'package_width_cm' => '10.00',
            'package_height_cm' => '10.00',
            'declared_value' => $declaredValue,
            'handoff_mode' => 'branch_dropoff',
        ]);
    }

    /** @return DeliveryQuote */
    private function quoteForOrder(int $orderId): array
    {
        $order = Order::query()->findOrFail($orderId);

        return app(DeliveryRules::class)->quote(
            FulfillmentMethod::Delivery,
            $order->delivery_destination,
            [new Product([
                'shipping_profile' => $order->shipping_profile,
                'package_weight_kg' => '1.000',
                'package_length_cm' => '25.00',
                'package_width_cm' => '20.00',
                'package_height_cm' => '10.00',
                'package_measurement_source' => 'estimated',
            ])],
        ) ?? throw new LogicException('A shipment factory requires a delivery quote.');
    }
}
