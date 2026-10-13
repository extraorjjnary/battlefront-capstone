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
    public const ESTIMATE_NOTICE = 'Battlefront demonstration estimates, not official LBC rates or verified production shipping charges. New Negros Occidental rates use straight-line OSM boundary-centroid proxies, not storefront or road distances. Delivery dates are provisional; prepaid orders require payment verification. This does not book or approve an LBC shipment.';

    public function __construct(private readonly DeliveryRules $deliveryRules) {}

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

        $zone = $this->deliveryRules->zone($quote['destination']);

        $pricingConfigured = $quote['pricing_configured'];

        return [
            ...$quote,
            'order_placement_allowed' => true,
            'zone' => [
                'rule_id' => $zone['rule_id'],
                'rules_version' => $zone['rules_version'],
                'region_code' => $zone['region_code'],
                'province_code' => $zone['province_code'],
                'city_code' => $zone['city_code'],
            ],
            'destination_label' => $zone['destination'],
            'payment_methods' => $zone['payment_methods'],
            'carrier' => $pricingConfigured ? Config::string('battlefront.delivery.carrier') : null,
            'packing_expectation' => $pricingConfigured ? $this->packingExpectation(ShippingProfile::from($quote['shipping_profile'])) : null,
            'product_subtotal' => $subtotal,
            'total' => bcadd($subtotal, $quote['delivery_fee'], 2),
            'eta_anchor_date' => $pricingConfigured ? $anchor->toDateString() : null,
            'eta_timezone' => $pricingConfigured ? $anchor->timezoneName : null,
            'estimated_delivery_start' => $pricingConfigured ? $anchor->addDays($quote['eta_min_days'])->toDateString() : null,
            'estimated_delivery_end' => $pricingConfigured ? $anchor->addDays($quote['eta_max_days'])->toDateString() : null,
            'notice' => $pricingConfigured
                ? self::ESTIMATE_NOTICE
                : Config::string('battlefront.delivery.unconfigured_route.notice'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function stored(Order $order): ?array
    {
        $shipment = $order->shipment;

        if ($order->delivery_destination === null || $order->shipping_profile === null) {
            return null;
        }

        $pricingConfigured = ($order->delivery_quote_metadata['pricing_status'] ?? null) !== 'not_configured';

        return [
            'origin_city' => $order->delivery_origin_city,
            'metadata' => $order->delivery_quote_metadata,
            'destination' => $order->delivery_destination,
            'is_demo' => $order->delivery_is_demo,
            'assumption_label' => $order->delivery_assumption_label,
            'pricing_configured' => $pricingConfigured,
            'shipping_profile' => $order->shipping_profile->value,
            'base_fee' => $pricingConfigured ? $order->delivery_base_fee : null,
            'handling_surcharge' => $pricingConfigured ? $order->handling_surcharge : null,
            'delivery_fee' => $order->delivery_fee,
            'preparation_days' => $shipment?->preparation_days,
            'transit_min_days' => $shipment?->transit_min_days,
            'transit_max_days' => $shipment?->transit_max_days,
            'eta_min_days' => $shipment?->eta_min_days,
            'eta_max_days' => $shipment?->eta_max_days,
            'carrier' => $shipment?->carrier,
            'packing_expectation' => $pricingConfigured ? $this->packingExpectation($order->shipping_profile) : null,
            'product_subtotal' => $order->product_subtotal,
            'total' => $order->total_amount,
            'notice' => $pricingConfigured
                ? self::ESTIMATE_NOTICE
                : Config::string('battlefront.delivery.unconfigured_route.notice'),
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
