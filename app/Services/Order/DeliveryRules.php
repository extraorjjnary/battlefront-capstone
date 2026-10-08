<?php

namespace App\Services\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\ShippingProfile;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * @phpstan-type DeliveryDestination array{base_fee: numeric-string, transit_min_days: int, transit_max_days: int}
 * @phpstan-type HandlingRules array{surcharge: numeric-string, preparation_days: int}
 * @phpstan-type DeliveryQuote array{
 *     origin_city: string, destination: string, is_demo: bool, assumption_label: string,
 *     shipping_profile: string, base_fee: numeric-string, handling_surcharge: numeric-string,
 *     delivery_fee: numeric-string, preparation_days: int, transit_min_days: int, transit_max_days: int,
 *     eta_min_days: int, eta_max_days: int
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
        $destinations = $this->destinations();

        if (! array_key_exists($destination, $destinations)) {
            throw new DomainException('Unsupported delivery destination.');
        }

        return $destinations[$destination];
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
        $zone = $this->destination($destination);
        $profile = $this->highestProfile($products);
        $handling = $this->handling($profile);

        return [
            'origin_city' => Config::string('battlefront.delivery.origin_city'),
            'destination' => $destination,
            'is_demo' => Config::boolean('battlefront.delivery.is_demo'),
            'assumption_label' => Config::string('battlefront.delivery.assumption_label'),
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
