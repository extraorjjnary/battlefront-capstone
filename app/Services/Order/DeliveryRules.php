<?php

namespace App\Services\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\ShippingProfile;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * @phpstan-type DeliveryDestination array{base_fee: numeric-string, transit_min_days: int|null, transit_max_days: int|null}
 * @phpstan-type HandlingRules array{surcharge: numeric-string, preparation_days: int}
 * @phpstan-type DeliveryQuote array{
 *     origin_city: string, destination: string, is_demo: bool, assumption_label: string, pricing_configured: bool,
 *     shipping_profile: string, base_fee: numeric-string, handling_surcharge: numeric-string,
 *     delivery_fee: numeric-string, preparation_days: int|null, transit_min_days: int|null, transit_max_days: int|null,
 *     eta_min_days: int|null, eta_max_days: int|null
 * }
 */
class DeliveryRules
{
    /** @return array<string, DeliveryDestination> */
    public function destinations(): array
    {
        /** @var array<string, DeliveryDestination> $destinations */
        $destinations = Config::array('battlefront.delivery.destinations');

        return $destinations;
    }

    /** @return DeliveryDestination */
    public function destination(string $destination): array
    {
        $zone = $this->zone($destination);

        return ['base_fee' => $zone['base_fee'], 'transit_min_days' => $zone['transit_min_days'], 'transit_max_days' => $zone['transit_max_days']];
    }

    /** @return array<string, mixed> */
    public function zone(string $destination): array
    {
        $locations = app(PsgcDeliveryLocations::class);
        $city = preg_match('/\A[0-9]{10}\z/', $destination) === 1
            ? $locations->city($destination)
            : $locations->city($locations->destination($destination)['code']);
        $matchedRule = false;
        foreach (['cities' => $city['code'], 'provinces' => $city['province_code'], 'regions' => $city['region_code']] as $level => $code) {
            $zones = Config::array('battlefront.delivery.zones.'.$level);
            if ($code === null || ! array_key_exists($code, $zones)) {
                continue;
            }
            $matchedRule = true;
            $zone = $zones[$code];
            if (! is_array($zone) || ($zone['enabled'] ?? true) !== true) {
                break;
            }
            if (isset($zone['legacy_destination'])) {
                $legacy = $this->destinations()[$zone['legacy_destination']] ?? null;
                if ($legacy === null) {
                    break;
                }
                $zone = [...$legacy, ...$zone];
            }
            if (! is_string($zone['base_fee'] ?? null) || preg_match('/\A[0-9]{1,10}\.[0-9]{2}\z/', $zone['base_fee']) !== 1
                || ! is_int($zone['transit_min_days'] ?? null) || ! is_int($zone['transit_max_days'] ?? null)
                || $zone['transit_min_days'] < 0 || $zone['transit_max_days'] < $zone['transit_min_days']
                || ! is_array($zone['payment_methods'] ?? null) || ! array_is_list($zone['payment_methods']) || $zone['payment_methods'] === []
                || count(array_filter($zone['payment_methods'], is_string(...))) !== count($zone['payment_methods'])
                || array_diff($zone['payment_methods'], ['gcash', 'maya', 'cod']) !== []) {
                break;
            }

            return [...$zone, 'configured' => true, 'rule_id' => $level.':'.$code, 'city_code' => $city['code'],
                'region_code' => $city['region_code'], 'province_code' => $city['province_code'],
                'destination' => $city['destination'], 'rules_version' => Config::string('battlefront.delivery.rules_version')];
        }

        if ($matchedRule) {
            throw new DomainException('Delivery is unavailable for this location.');
        }

        return [
            'configured' => false,
            'base_fee' => Config::string('battlefront.delivery.unconfigured_route.base_fee'),
            'transit_min_days' => null,
            'transit_max_days' => null,
            'payment_methods' => Config::array('battlefront.delivery.unconfigured_route.payment_methods'),
            'rule_id' => 'unconfigured:'.$city['code'],
            'city_code' => $city['code'],
            'region_code' => $city['region_code'],
            'province_code' => $city['province_code'],
            'destination' => $city['destination'],
            'rules_version' => Config::string('battlefront.delivery.rules_version'),
        ];
    }

    /** @return HandlingRules */
    public function handling(ShippingProfile $profile): array
    {
        /** @var HandlingRules $rules */
        $rules = Config::array("battlefront.delivery.profiles.{$profile->value}");

        return $rules;
    }

    /**
     * Select one handling requirement from current server-loaded products.
     *
     * @param  iterable<Product>  $products
     */
    public function highestProfile(iterable $products): ShippingProfile
    {
        $highest = null;

        foreach ($products as $product) {
            $profile = $product->getAttribute('shipping_profile');

            if (! $profile instanceof ShippingProfile) {
                throw new InvalidArgumentException('Products must have a loaded shipping profile.');
            }

            if ($highest === null || $profile->priority() > $highest->priority()) {
                $highest = $profile;
            }
        }

        if ($highest === null) {
            throw new InvalidArgumentException('Delivery quotes require at least one product.');
        }

        return $highest;
    }

    /**
     * Return a relative delivery estimate; pickup has no delivery-zone quote.
     * Product quantities do not affect handling or preparation.
     *
     * @param  iterable<Product>  $products
     * @return DeliveryQuote|null
     */
    public function quote(FulfillmentMethod $fulfillmentMethod, ?string $destination, iterable $products): ?array
    {
        if ($fulfillmentMethod === FulfillmentMethod::Pickup) {
            return null;
        }

        $destination ??= '';
        // All code and legacy-name requests use the same zone precedence and eligibility.
        if (! preg_match('/\A[0-9]{10}\z/', $destination) && ! array_key_exists($destination, $this->destinations())) {
            throw new DomainException('Unsupported delivery destination.');
        }
        $zone = $this->zone($destination);
        $profile = $this->highestProfile($products);

        if ($zone['configured'] === false) {
            return [
                'origin_city' => Config::string('battlefront.delivery.origin_city'),
                'destination' => $destination,
                'is_demo' => true,
                'assumption_label' => Config::string('battlefront.delivery.unconfigured_route.assumption_label'),
                'pricing_configured' => false,
                'shipping_profile' => $profile->value,
                'base_fee' => '0.00',
                'handling_surcharge' => '0.00',
                'delivery_fee' => '0.00',
                'preparation_days' => null,
                'transit_min_days' => null,
                'transit_max_days' => null,
                'eta_min_days' => null,
                'eta_max_days' => null,
            ];
        }

        $handling = $this->handling($profile);

        return [
            'origin_city' => Config::string('battlefront.delivery.origin_city'),
            'destination' => $destination,
            'is_demo' => Config::boolean('battlefront.delivery.is_demo'),
            'assumption_label' => Config::string('battlefront.delivery.assumption_label'),
            'pricing_configured' => true,
            'shipping_profile' => $profile->value,
            'base_fee' => $zone['base_fee'],
            'handling_surcharge' => $handling['surcharge'],
            'delivery_fee' => bcadd($zone['base_fee'], $handling['surcharge'], 2),
            'preparation_days' => $handling['preparation_days'],
            'transit_min_days' => $zone['transit_min_days'],
            'transit_max_days' => $zone['transit_max_days'],
            'eta_min_days' => $handling['preparation_days'] + $zone['transit_min_days'],
            'eta_max_days' => $handling['preparation_days'] + $zone['transit_max_days'],
        ];
    }
}
