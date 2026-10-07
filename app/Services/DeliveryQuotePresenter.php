<?php

namespace App\Services;

use App\Enums\ShippingProfile;
use App\Models\Order;
use App\Services\Order\DeliveryRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/** @phpstan-import-type DeliveryQuote from DeliveryRules */
class DeliveryQuotePresenter
{
    public const ESTIMATE_NOTICE = 'Battlefront estimates, not live LBC quotations or tracking. Delivery dates are provisional and subject to payment verification.';

    /**
     * Calendar windows are checkout-only estimates anchored to the server quote date.
     *
     * @param  DeliveryQuote|null  $quote
     * @return array<string, mixed>
     */
    public function checkout(?array $quote, string $subtotal, CarbonImmutable $anchor): array
    {
        if ($quote === null || ! is_numeric($subtotal)) {
            throw new InvalidArgumentException('Delivery presentation requires a quote and numeric subtotal.');
        }

        return [
            ...$quote,
            'carrier' => Config::string('battlefront.delivery.carrier'),
            'packing_expectation' => $this->packingExpectation(ShippingProfile::from($quote['shipping_profile'])),
            'product_subtotal' => $subtotal,
            'total' => bcadd($subtotal, $quote['delivery_fee'], 2),
            'eta_anchor_date' => $anchor->toDateString(),
            'eta_timezone' => $anchor->timezoneName,
            'estimated_delivery_start' => $anchor->addDays($quote['eta_min_days'])->toDateString(),
            'estimated_delivery_end' => $anchor->addDays($quote['eta_max_days'])->toDateString(),
            'notice' => self::ESTIMATE_NOTICE,
        ];
    }

    /** @return array<string, mixed>|null */
    public function stored(Order $order): ?array
    {
        $shipment = $order->shipment;

        if ($order->delivery_destination === null || $order->shipping_profile === null || $shipment === null) {
            return null;
        }

        return [
            'origin_city' => $order->delivery_origin_city,
            'destination' => $order->delivery_destination,
            'is_demo' => $order->delivery_is_demo,
            'assumption_label' => $order->delivery_assumption_label,
            'shipping_profile' => $order->shipping_profile->value,
            'base_fee' => $order->delivery_base_fee,
            'handling_surcharge' => $order->handling_surcharge,
            'delivery_fee' => $order->delivery_fee,
            'preparation_days' => $shipment->preparation_days,
            'transit_min_days' => $shipment->transit_min_days,
            'transit_max_days' => $shipment->transit_max_days,
            'eta_min_days' => $shipment->eta_min_days,
            'eta_max_days' => $shipment->eta_max_days,
            'carrier' => $shipment->carrier,
            'packing_expectation' => $this->packingExpectation($order->shipping_profile),
            'product_subtotal' => $order->product_subtotal,
            'total' => $order->total_amount,
            'notice' => self::ESTIMATE_NOTICE,
        ];
    }

    private function packingExpectation(ShippingProfile $profile): string
    {
        return match ($profile) {
            ShippingProfile::Standard => 'Standard packing',
            ShippingProfile::Fragile => 'Extra protective packing for fragile items',
            ShippingProfile::Bulky => 'Packing and handling for bulky items',
        };
    }
}
