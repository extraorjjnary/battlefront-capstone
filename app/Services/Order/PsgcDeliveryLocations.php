<?php

namespace App\Services\Order;

use DomainException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type Barangay array{code: string, name: string}
 * @phpstan-type DeliveryLocation array{
 *     destination: string, code: string, name: string, type: string, city_class: string|null,
 *     province: array{code: string, name: string}|null, barangays: list<Barangay>
 * }
 * @phpstan-type AddressPrefill array{delivery_destination: string, delivery_barangay_code: string, delivery_address_details: string}
 * @phpstan-type AddressComponents array{destination: string, city_code: string, barangay_code: string, details: string}
 */
class PsgcDeliveryLocations
{
    public const VERSION = '2026-03-31.v2';

    /** Compatibility aliases are geography identifiers, independent of delivery coverage. */
    public const LEGACY_DESTINATIONS = [
        'Sagay City', 'Escalante City', 'Cadiz City', 'Toboso', 'Manapla', 'Calatrava',
        'Victorias City', 'E.B. Magalona', 'San Carlos City', 'Silay City', 'Talisay City', 'Bacolod City',
    ];

    /** @var array<string, mixed>|null */
    private ?array $geography = null;

    /** @var array<string, array<string, mixed>> */
    private array $citiesByCode = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $regionalBarangays = [];

    /** @return array<string, mixed> */
    public function geography(): array
    {
        if ($this->geography !== null) {
            return $this->geography;
        }
        $data = json_decode(File::get(resource_path('data/psgc-nationwide-'.self::VERSION.'.json')), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 2 || ($data['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('The nationwide PSGC dataset has an unsupported schema.');
        }
        foreach (['regions', 'provinces', 'cities'] as $level) {
            if (! is_array($data[$level] ?? null) || ! array_is_list($data[$level])) {
                throw new RuntimeException('Invalid nationwide PSGC hierarchy.');
            }
            $codes = [];
            foreach ($data[$level] as $record) {
                if (! is_array($record) || ! $this->isNamedCode($record) || isset($codes[$record['code']])) {
                    throw new RuntimeException('Invalid or duplicate nationwide PSGC code.');
                }
                $codes[$record['code']] = true;
            }
        }
        foreach ([...$data['provinces'], ...$data['cities']] as $record) {
            if (! in_array($record['region_code'] ?? null, array_column($data['regions'], 'code'), true)) {
                throw new RuntimeException('Invalid PSGC region parent.');
            }
            if (array_key_exists('province_code', $record) && $record['province_code'] !== null) {
                $parents = array_filter($data['provinces'], fn (array $province): bool => $province['code'] === $record['province_code'] && $province['region_code'] === $record['region_code']);
                if (count($parents) !== 1) {
                    throw new RuntimeException('Invalid PSGC province parent.');
                }
            }
        }
        $this->citiesByCode = array_column($data['cities'], null, 'code');

        return $this->geography = $data;
    }

    /** @return array<string, mixed> */
    public function city(string $code): array
    {
        $this->geography();
        if (isset($this->citiesByCode[$code])) {
            return $this->citiesByCode[$code];
        }
        throw new DomainException('Select a valid Philippine city or municipality.');
    }

    /** @return list<array<string, mixed>> */
    public function lookup(string $level, ?string $parent = null): array
    {
        $data = $this->geography();
        if ($level === 'regions') {
            return $data['regions'];
        }
        if ($level === 'city') {
            return [$this->city($parent ?? '')];
        }
        if ($level === 'barangays') {
            return $this->cityBarangays($parent ?? '');
        }
        if (! in_array($parent, array_column($data['regions'], 'code'), true)
            && ! in_array($parent, array_column($data['provinces'], 'code'), true)) {
            throw new DomainException('Select a valid PSGC parent.');
        }
        if ($level === 'provinces') {
            if (! in_array($parent, array_column($data['regions'], 'code'), true)) {
                throw new DomainException('Select a valid PSGC region.');
            }

            return array_values(array_filter($data['provinces'], fn (array $record): bool => $record['region_code'] === $parent));
        }
        if ($level === 'cities') {
            return array_values(array_filter($data['cities'], fn (array $record): bool => $record['province_code'] === $parent
                || ($record['region_code'] === $parent && $record['province_code'] === null)));
        }
        throw new DomainException('Select a valid PSGC level.');
    }

    /** @return list<array<string, mixed>> */
    public function cityBarangays(string $code): array
    {
        $city = $this->city($code);
        $region = $city['region_code'];
        if (! isset($this->regionalBarangays[$region])) {
            $data = json_decode(File::get(resource_path('data/psgc-barangays-'.self::VERSION.'-'.$region.'.json')), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ($data['region_code'] ?? null) !== $region || ! is_array($data['barangays'] ?? null) || ! array_is_list($data['barangays'])) {
                throw new RuntimeException('Invalid PSGC barangay partition.');
            }
            $seen = [];
            foreach ($data['barangays'] as $record) {
                if (! is_array($record) || ! $this->isNamedCode($record) || ! is_string($record['city_code'] ?? null) || isset($seen[$record['code']])) {
                    throw new RuntimeException('Invalid or duplicate PSGC barangay.');
                }
                $parent = $this->city($record['city_code']);
                $prefix = $parent['code'] === '1380600000' ? 5 : 7;
                if ($parent['region_code'] !== $region || substr($record['code'], 0, $prefix) !== substr($parent['code'], 0, $prefix)) {
                    throw new RuntimeException('Invalid PSGC barangay parent.');
                }
                $seen[$record['code']] = true;
            }
            $this->regionalBarangays[$region] = $data['barangays'];
        }

        return array_values(array_filter($this->regionalBarangays[$region], fn (array $record): bool => $record['city_code'] === $code));
    }

    /** @return array<string, string> */
    public function hierarchyErrors(string $region, ?string $province, string $cityCode): array
    {
        try {
            $city = $this->city($cityCode);
        } catch (DomainException) {
            return ['city_code' => 'Select a valid city or municipality.'];
        }
        if ($city['region_code'] !== $region) {
            return ['region_code' => 'The selected city does not belong to this region.'];
        }
        if ($city['province_code'] !== ($province === '' ? null : $province)) {
            return ['province_code' => 'Select the correct province, or no province for an independent locality.'];
        }

        return [];
    }

    /** @var list<DeliveryLocation>|null */
    private ?array $locations = null;

    /** @return list<DeliveryLocation> */
    public function destinations(): array
    {
        if ($this->locations !== null) {
            return $this->locations;
        }

        try {
            $dataset = json_decode(File::get(resource_path('data/psgc-delivery-2026-03-31.v1.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The local PSGC delivery dataset is invalid.', previous: $exception);
        }

        if (! is_array($dataset) || ($dataset['schema_version'] ?? null) !== 1 || ! is_array($dataset['destinations'] ?? null)
            || ! array_is_list($dataset['destinations'])) {
            throw new RuntimeException('The local PSGC delivery dataset has an unsupported schema.');
        }

        $locations = [];
        $codes = [];

        foreach ($dataset['destinations'] as $location) {
            if (! is_array($location)
                || ! $this->isNamedCode($location)
                || ! str_ends_with($location['code'], '000')
                || ! is_string($location['destination'] ?? null)
                || ! in_array($location['type'] ?? null, ['city', 'municipality'], true)
                || ! array_key_exists('city_class', $location)
                || ! in_array($location['city_class'], [null, 'CC', 'HUC'], true)
                || ! array_key_exists('province', $location)
                || ($location['province'] !== null && (! is_array($location['province']) || ! $this->isNamedCode($location['province'])))
                || ($location['city_class'] === 'HUC' && $location['province'] !== null)
                || ! is_array($location['barangays'] ?? null)
                || ! array_is_list($location['barangays'])
                || $location['barangays'] === []
                || isset($locations[$location['destination']])
                || isset($codes[$location['code']])) {
                throw new RuntimeException('The local PSGC delivery dataset contains an invalid destination.');
            }

            $codes[$location['code']] = true;

            foreach ($location['barangays'] as $barangay) {
                if (! is_array($barangay) || ! $this->isNamedCode($barangay)
                    || substr($barangay['code'], 0, 7) !== substr($location['code'], 0, 7)
                    || isset($codes[$barangay['code']])) {
                    throw new RuntimeException('The local PSGC delivery dataset contains an invalid barangay.');
                }

                $codes[$barangay['code']] = true;
            }

            /** @var DeliveryLocation $location */
            $locations[$location['destination']] = $location;
        }

        $supported = self::LEGACY_DESTINATIONS;

        if (count($locations) !== count($supported) || array_diff($supported, array_keys($locations)) !== []) {
            throw new RuntimeException('The local PSGC dataset must match the configured delivery coverage.');
        }

        return $this->locations = array_map(fn (string $destination): array => $locations[$destination], $supported);
    }

    /** @return DeliveryLocation */
    public function destination(string $destination): array
    {
        if (preg_match('/\A[0-9]{10}\z/', $destination) === 1) {
            $city = $this->city($destination);
            $province = null;
            foreach ($this->geography()['provinces'] as $candidate) {
                if ($candidate['code'] === $city['province_code']) {
                    $province = ['code' => $candidate['code'], 'name' => $candidate['name']];
                }
            }

            return [
                'destination' => $city['destination'], 'code' => $city['code'], 'name' => $city['name'],
                'type' => $city['type'], 'city_class' => $city['city_class'], 'province' => $province,
                'barangays' => array_map(fn (array $barangay): array => ['code' => $barangay['code'], 'name' => $barangay['name']], $this->cityBarangays($destination)),
            ];
        }
        if (! in_array($destination, self::LEGACY_DESTINATIONS, true)) {
            throw new DomainException('Unsupported delivery destination.');
        }

        foreach ($this->destinations() as $location) {
            if ($location['destination'] === $destination) {
                return $location;
            }
        }

        throw new RuntimeException('The configured destination has no local PSGC mapping.');
    }

    /** @return Barangay|null */
    public function barangay(string $destination, string $code): ?array
    {
        foreach ($this->destination($destination)['barangays'] as $barangay) {
            if ($barangay['code'] === $code) {
                return $barangay;
            }
        }

        return null;
    }

    public function formatAddress(string $destination, string $barangayCode, string $details): string
    {
        $location = $this->destination($destination);
        $barangay = $this->barangay($destination, $barangayCode);

        if ($barangay === null) {
            throw new DomainException('Select a barangay belonging to the selected city or municipality.');
        }

        $details = trim($details);

        if ($details === '') {
            throw new InvalidArgumentException('Enter the house, building, street, or landmark details.');
        }

        $parts = [$details, $barangay['name'], $location['destination']];

        if ($location['province'] !== null) {
            $parts[] = $location['province']['name'];
        }

        return implode(', ', $parts);
    }

    /** @return array<string, string> */
    public function addressErrors(string $destination, string $barangayCode, string $details): array
    {
        if ($this->barangay($destination, $barangayCode) === null) {
            return ['barangay_code' => 'Select a barangay belonging to the selected city or municipality.'];
        }

        if (mb_strlen($this->formatAddress($destination, $barangayCode, $details)) > 255) {
            return ['address_details' => 'The complete delivery address may not exceed 255 characters. Shorten the house, building, street, or landmark details.'];
        }

        return [];
    }

    /** @return AddressComponents */
    public function components(string $destination, string $barangayCode, string $details): array
    {
        $this->formatAddress($destination, $barangayCode, $details);

        return [
            'destination' => $destination,
            'city_code' => $this->destination($destination)['code'],
            'barangay_code' => $barangayCode,
            'details' => trim($details),
        ];
    }

    /**
     * @param  array<mixed>|null  $components
     * @return AddressPrefill
     */
    public function prefill(?string $address, ?array $components = null): array
    {
        if ($components !== null
            && is_string($components['destination'] ?? null)
            && is_string($components['city_code'] ?? null)
            && is_string($components['barangay_code'] ?? null)
            && is_string($components['details'] ?? null)
            && trim($components['details']) !== ''
            && (in_array($components['destination'], self::LEGACY_DESTINATIONS, true) || $this->knownCity($components['destination']))
            && $this->destination($components['destination'])['code'] === $components['city_code']
            && $this->addressErrors($components['destination'], $components['barangay_code'], $components['details']) === []) {
            return [
                'delivery_destination' => $components['destination'],
                'delivery_barangay_code' => $components['barangay_code'],
                'delivery_address_details' => $components['details'],
            ];
        }

        $prefill = ['delivery_destination' => '', 'delivery_barangay_code' => '', 'delivery_address_details' => ''];

        if ($address === null || trim($address) === '') {
            return $prefill;
        }

        $parts = array_map(trim(...), explode(',', $address));

        if ($this->normalizeName((string) end($parts)) === 'negros occidental') {
            array_pop($parts);
        }

        $cityName = $this->normalizeName((string) array_pop($parts));
        $matches = array_filter($this->destinations(), fn (array $location): bool => in_array(
            $cityName,
            [$this->normalizeName($location['destination']), $this->normalizeName($location['name'])],
            true,
        ));

        if (count($matches) !== 1) {
            return $prefill;
        }

        $location = array_values($matches)[0];
        $prefill['delivery_destination'] = $location['destination'];
        $barangayName = $this->normalizeBarangay((string) array_pop($parts));
        $barangays = array_filter($location['barangays'], fn (array $barangay): bool => $this->normalizeBarangay($barangay['name']) === $barangayName);
        $details = trim(implode(', ', $parts));

        if (count($barangays) === 1 && $details !== '') {
            $prefill['delivery_barangay_code'] = array_values($barangays)[0]['code'];
            $prefill['delivery_address_details'] = $details;
        }

        return $prefill;
    }

    /** @param array<mixed> $record */
    private function isNamedCode(array $record): bool
    {
        return is_string($record['code'] ?? null) && preg_match('/\A[0-9]{10}\z/', $record['code']) === 1
            && is_string($record['name'] ?? null) && trim($record['name']) !== '';
    }

    private function knownCity(string $code): bool
    {
        try {
            $this->city($code);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function normalizeBarangay(string $name): string
    {
        return preg_replace('/\A(?:brgy\.?|barangay)\s+/u', '', $this->normalizeName($name)) ?? '';
    }
}
