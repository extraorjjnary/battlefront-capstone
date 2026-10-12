<?php

use App\Services\Order\DeliveryRules;
use App\Services\Order\PsgcDeliveryLocations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

test('each supported destination resolves its official PSGC identity and complete barangay list', function (string $destination, string $code, string $name, int $count) {
    Http::preventStrayRequests();
    $locations = app(PsgcDeliveryLocations::class);
    $location = $locations->destination($destination);

    expect($location['code'])->toBe($code);
    expect($location['name'])->toBe($name);
    expect($location['barangays'])->toHaveCount($count);
    foreach ($location['barangays'] as $barangay) {
        expect($barangay['code'])->toBeString()->toStartWith(substr($code, 0, 7));
        expect($locations->barangay($destination, $barangay['code']))->toBe($barangay);
    }
    Http::assertNothingSent();
})->with([
    'Sagay' => ['Sagay City', '1804523000', 'City of Sagay', 25],
    'Escalante' => ['Escalante City', '1804509000', 'City of Escalante', 21],
    'Cadiz' => ['Cadiz City', '1804504000', 'City of Cadiz', 22],
    'Toboso' => ['Toboso', '1804529000', 'Toboso', 9],
    'Manapla' => ['Manapla', '1804518000', 'Manapla', 12],
    'Calatrava' => ['Calatrava', '1804505000', 'Calatrava', 40],
    'Victorias' => ['Victorias City', '1804531000', 'City of Victorias', 26],
    'Magalona' => ['E.B. Magalona', '1804508000', 'Enrique B. Magalona', 23],
    'San Carlos' => ['San Carlos City', '1804524000', 'City of San Carlos', 18],
    'Silay' => ['Silay City', '1804526000', 'City of Silay', 16],
    'Talisay' => ['Talisay City', '1804528000', 'City of Talisay', 27],
    'Bacolod' => ['Bacolod City', '1830200000', 'City of Bacolod', 61],
]);

test('PSGC coverage uses exactly the configured keys and keeps Bacolod administratively independent', function () {
    $locations = app(PsgcDeliveryLocations::class)->destinations();

    expect(array_column($locations, 'destination'))->toBe(array_keys(app(DeliveryRules::class)->destinations()));
    expect(array_sum(array_map(fn (array $location): int => count($location['barangays']), $locations)))->toBe(300);
    expect($locations[11]['city_class'])->toBe('HUC');
    expect($locations[11]['province'])->toBeNull();
    foreach (array_slice($locations, 0, 11) as $location) {
        expect($location['province'])->toBe(['code' => '1804500000', 'name' => 'Negros Occidental']);
    }
});

test('PSGC lookup rejects unsupported destinations and barangays from a different locality', function () {
    $locations = app(PsgcDeliveryLocations::class);

    expect(fn () => $locations->destination('Guihulngan City'))->toThrow(DomainException::class, 'Unsupported delivery destination.');
    expect($locations->barangay('Sagay City', '1804524001'))->toBeNull();
    expect($locations->barangay('Sagay City', '9999999999'))->toBeNull();
    expect(fn () => $locations->formatAddress('Sagay City', '1804524001', '12 Mabini Street'))
        ->toThrow(DomainException::class, 'Select a barangay belonging to the selected city or municipality.');
    expect(fn () => $locations->formatAddress('Sagay City', '1804523018', ' '))
        ->toThrow(InvalidArgumentException::class, 'Enter the house, building, street, or landmark details.');
});

test('formatted addresses use official barangay names and the correct administrative suffix', function (string $destination, string $barangay, string $expected) {
    expect(app(PsgcDeliveryLocations::class)->formatAddress($destination, $barangay, '  12 Mabini Street  '))->toBe($expected);
})->with([
    ['Sagay City', '1804523018', '12 Mabini Street, Poblacion I, Sagay City, Negros Occidental'],
    ['Bacolod City', '1830200008', '12 Mabini Street, Barangay 13, Bacolod City'],
]);

test('saved components prefill directly while invalid components fall back to conservative text matching', function () {
    $locations = app(PsgcDeliveryLocations::class);
    $components = $locations->components('Sagay City', '1804523018', 'Unit 2, Lopez Building, Mabini Street');

    expect($components)->toBe([
        'destination' => 'Sagay City', 'city_code' => '1804523000',
        'barangay_code' => '1804523018', 'details' => 'Unit 2, Lopez Building, Mabini Street',
    ]);
    expect($locations->prefill('Existing ambiguous legacy text', $components))->toBe([
        'delivery_destination' => 'Sagay City', 'delivery_barangay_code' => '1804523018',
        'delivery_address_details' => 'Unit 2, Lopez Building, Mabini Street',
    ]);
    $components['city_code'] = '1804524000';
    expect($locations->prefill('Existing ambiguous legacy text', $components))->toBe([
        'delivery_destination' => '', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
    ]);
    expect($locations->prefill('12 Mabini Street, Poblacion I, Sagay City', $components))->toBe([
        'delivery_destination' => 'Sagay City', 'delivery_barangay_code' => '1804523018', 'delivery_address_details' => '12 Mabini Street',
    ]);
});

test('profile prefills extract only unambiguous location suffixes', function (?string $address, string $destination, string $barangay, string $details) {
    expect(app(PsgcDeliveryLocations::class)->prefill($address))->toBe([
        'delivery_destination' => $destination,
        'delivery_barangay_code' => $barangay,
        'delivery_address_details' => $details,
    ]);
})->with([
    'missing' => [null, '', '', ''],
    'blank' => [' ', '', '', ''],
    'unstructured' => ['Saved address', '', '', ''],
    'city only' => ['12 Mabini Street, Sagay City', 'Sagay City', '', ''],
    'unknown barangay' => ['12 Mabini Street, Brgy. Poblacion, Sagay City', 'Sagay City', '', ''],
    'unsupported city' => ['12 Mabini Street, Poblacion I, Guihulngan City', '', '', ''],
    'wrong barangay' => ['12 Mabini Street, Bagonbon, Sagay City', 'Sagay City', '', ''],
    'complete' => ['12 Mabini Street, Poblacion I, Sagay City, Negros Occidental', 'Sagay City', '1804523018', '12 Mabini Street'],
    'official city and prefix' => ['12 Mabini Street, Brgy. Poblacion II, City of Sagay', 'Sagay City', '1804523019', '12 Mabini Street'],
    'case and spacing' => [' 12 Mabini Street , barangay poblacion i , sagay city ', 'Sagay City', '1804523018', '12 Mabini Street'],
    'commas in details' => ['Unit 2, Lopez Building, Mabini Street, Poblacion I, Sagay City', 'Sagay City', '1804523018', 'Unit 2, Lopez Building, Mabini Street'],
    'Bacolod numbered barangay' => ['12 Lizares Street, Brgy. 13, Bacolod City, Negros Occidental', 'Bacolod City', '1830200008', '12 Lizares Street'],
    'no street details' => ['Poblacion I, Sagay City', 'Sagay City', '', ''],
]);

test('the service rejects malformed local datasets rather than accepting partial geography', function (Closure $change, string $message) {
    $path = resource_path('data/psgc-delivery-2026-03-31.v1.json');
    $dataset = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $payload = $change($dataset);
    File::partialMock()->shouldReceive('get')->once()->with($path)->andReturn($payload);

    expect(fn () => app(PsgcDeliveryLocations::class)->destinations())->toThrow(RuntimeException::class, $message);
})->with([
    'invalid JSON' => [fn (array $dataset): string => '{', 'The local PSGC delivery dataset is invalid.'],
    'unsupported schema' => [function (array $dataset): string {
        $dataset['schema_version'] = 2;

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC delivery dataset has an unsupported schema.'],
    'missing destination' => [function (array $dataset): string {
        array_pop($dataset['destinations']);

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC dataset must match the configured delivery coverage.'],
    'unsupported destination' => [function (array $dataset): string {
        $dataset['destinations'][0]['destination'] = 'Guihulngan City';

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC dataset must match the configured delivery coverage.'],
    'numeric code' => [function (array $dataset): string {
        $dataset['destinations'][0]['code'] = 1804523000;

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC delivery dataset contains an invalid destination.'],
    'duplicate barangay' => [function (array $dataset): string {
        $dataset['destinations'][0]['barangays'][] = $dataset['destinations'][0]['barangays'][0];

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC delivery dataset contains an invalid barangay.'],
    'mismatched parent' => [function (array $dataset): string {
        $dataset['destinations'][0]['barangays'][0] = ['code' => '1804524001', 'name' => 'Bagonbon'];

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC delivery dataset contains an invalid barangay.'],
    'incorrect HUC province' => [function (array $dataset): string {
        $dataset['destinations'][11]['province'] = ['code' => '1804500000', 'name' => 'Negros Occidental'];

        return json_encode($dataset, JSON_THROW_ON_ERROR);
    }, 'The local PSGC delivery dataset contains an invalid destination.'],
]);
