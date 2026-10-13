<?php

use App\Enums\FulfillmentMethod;
use App\Enums\ShippingProfile;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Order\DeliveryRules;
use Illuminate\Support\Facades\Http;

test('all configured destinations resolve approved fees and transit ranges without external requests', function (
    string $destination,
    string $baseFee,
    int $transitMinimum,
    int $transitMaximum,
    int $etaMinimum,
    int $etaMaximum,
) {
    Http::preventStrayRequests();
    $rules = app(DeliveryRules::class);
    $product = Product::factory()->standard()->make(['category_id' => 1]);

    $quote = $rules->quote(FulfillmentMethod::Delivery, $destination, [$product]);

    expect($rules->destination($destination))->toBe([
        'base_fee' => $baseFee,
        'transit_min_days' => $transitMinimum,
        'transit_max_days' => $transitMaximum,
    ]);
    expect($quote)->toBe([
        'origin_city' => 'Sagay City',
        'destination' => $destination,
        'is_demo' => true,
        'assumption_label' => 'Battlefront demonstration estimates. New Negros Occidental rates use OSM administrative-boundary centroids as straight-line proxies; they are not official LBC rates or verified production shipping charges.',
        'pricing_configured' => true,
        'shipping_profile' => 'standard',
        'base_fee' => $baseFee,
        'handling_surcharge' => '0.00',
        'delivery_fee' => $baseFee,
        'preparation_days' => 1,
        'transit_min_days' => $transitMinimum,
        'transit_max_days' => $transitMaximum,
        'eta_min_days' => $etaMinimum,
        'eta_max_days' => $etaMaximum,
    ]);
    Http::assertNothingSent();
})->with([
    'Sagay City' => ['Sagay City', '80.00', 0, 1, 1, 2],
    'Escalante City' => ['Escalante City', '100.00', 1, 1, 2, 2],
    'Cadiz City' => ['Cadiz City', '120.00', 1, 1, 2, 2],
    'Toboso' => ['Toboso', '140.00', 1, 1, 2, 2],
    'Manapla' => ['Manapla', '160.00', 1, 1, 2, 2],
    'Calatrava' => ['Calatrava', '180.00', 1, 1, 2, 2],
    'Victorias City' => ['Victorias City', '180.00', 1, 1, 2, 2],
    'E.B. Magalona' => ['E.B. Magalona', '200.00', 1, 2, 2, 3],
    'San Carlos City' => ['San Carlos City', '220.00', 1, 2, 2, 3],
    'Silay City' => ['Silay City', '220.00', 1, 2, 2, 3],
    'Talisay City' => ['Talisay City', '240.00', 1, 2, 2, 3],
    'Bacolod City' => ['Bacolod City', '250.00', 1, 2, 2, 3],
]);

test('destination listing contains exactly the supported destinations', function () {
    expect(array_keys(app(DeliveryRules::class)->destinations()))->toBe([
        'Sagay City', 'Escalante City', 'Cadiz City', 'Toboso', 'Manapla', 'Calatrava',
        'Victorias City', 'E.B. Magalona', 'San Carlos City', 'Silay City', 'Talisay City', 'Bacolod City',
    ]);
});

test('new Negros Occidental destinations use their reviewed centroid distance bands', function (
    string $cityCode,
    string $distance,
    string $distanceBand,
    string $baseFee,
    int $transitMinimum,
    int $transitMaximum,
) {
    $rules = app(DeliveryRules::class);
    $product = Product::factory()->standard()->make(['category_id' => 1]);
    $zone = $rules->zone($cityCode);
    $quote = $rules->quote(FulfillmentMethod::Delivery, $cityCode, [$product]);
    /** @var array{latitude: float, longitude: float, ...} $origin */
    $origin = config('battlefront.delivery.negros_occidental_distance_method.origin');
    /** @var array{latitude: float, longitude: float, ...} $proxy */
    $proxy = config("battlefront.delivery.negros_occidental_distance_proxies.{$cityCode}");
    /** @var int $earthRadiusKm */
    $earthRadiusKm = config('battlefront.delivery.negros_occidental_distance_method.earth_radius_km');
    $toRadians = static fn (float $degrees): float => deg2rad($degrees);
    $latitudeDelta = $toRadians($proxy['latitude'] - $origin['latitude']);
    $longitudeDelta = $toRadians($proxy['longitude'] - $origin['longitude']);
    $haversine = sin($latitudeDelta / 2) ** 2
        + cos($toRadians($origin['latitude'])) * cos($toRadians($proxy['latitude'])) * sin($longitudeDelta / 2) ** 2;
    $calculatedDistance = 2 * $earthRadiusKm * asin(sqrt($haversine));

    expect(config("battlefront.delivery.negros_occidental_distance_proxies.{$cityCode}.distance_km"))->toBe((float) $distance);
    expect(round($calculatedDistance, 1))->toBe((float) $distance);
    expect(config("battlefront.delivery.negros_occidental_distance_proxies.{$cityCode}.distance_band"))->toBe($distanceBand);
    expect($zone['configured'])->toBeTrue();
    expect($zone['base_fee'])->toBe($baseFee);
    expect($zone['transit_min_days'])->toBe($transitMinimum);
    expect($zone['transit_max_days'])->toBe($transitMaximum);
    expect($zone['payment_methods'])->toBe(['gcash', 'maya', 'cod']);
    expect($quote)->pricing_configured->toBeTrue()
        ->base_fee->toBe($baseFee)
        ->delivery_fee->toBe($baseFee)
        ->transit_min_days->toBe($transitMinimum)
        ->transit_max_days->toBe($transitMaximum)
        ->eta_min_days->toBe($transitMinimum + 1)
        ->eta_max_days->toBe($transitMaximum + 1);
})->with([
    'Binalbagan' => ['1804503000', '99.0', 'km_60_100', '280.00', 2, 3],
    'Candoni' => ['1804506000', '145.5', 'km_125_155', '360.00', 3, 4],
    'Cauayan' => ['1804507000', '134.1', 'km_125_155', '360.00', 3, 4],
    'City of Bago' => ['1804502000', '74.9', 'km_60_100', '280.00', 2, 3],
    'City of Himamaylan' => ['1804510000', '106.8', 'km_100_125', '320.00', 2, 3],
    'City of Kabankalan' => ['1804515000', '120.5', 'km_100_125', '320.00', 2, 3],
    'City of La Carlota' => ['1804516000', '75.1', 'km_60_100', '280.00', 2, 3],
    'City of Sipalay' => ['1804527000', '168.8', 'km_155_180', '400.00', 3, 4],
    'Hinigaran' => ['1804511000', '92.8', 'km_60_100', '280.00', 2, 3],
    'Hinoba-an' => ['1804512000', '177.4', 'km_155_180', '400.00', 3, 4],
    'Ilog' => ['1804513000', '120.0', 'km_100_125', '320.00', 2, 3],
    'Isabela' => ['1804514000', '89.9', 'km_60_100', '280.00', 2, 3],
    'La Castellana' => ['1804517000', '77.1', 'km_60_100', '280.00', 2, 3],
    'Moises Padilla' => ['1804519000', '78.9', 'km_60_100', '280.00', 2, 3],
    'Murcia' => ['1804520000', '52.1', 'km_45_60', '220.00', 1, 2],
    'Pontevedra' => ['1804521000', '83.4', 'km_60_100', '280.00', 2, 3],
    'Pulupandan' => ['1804522000', '78.9', 'km_60_100', '280.00', 2, 3],
    'Salvador Benedicto' => ['1804532000', '41.4', 'km_30_45', '180.00', 1, 2],
    'San Enrique' => ['1804525000', '81.4', 'km_60_100', '280.00', 2, 3],
    'Valladolid' => ['1804530000', '80.7', 'km_60_100', '280.00', 2, 3],
]);

test('a missing or inconsistent centroid band fails closed', function () {
    config(['battlefront.delivery.negros_occidental_distance_proxies.1804520000.distance_band' => 'km_60_100']);

    expect(fn () => app(DeliveryRules::class)->zone('1804520000'))
        ->toThrow(DomainException::class, 'Delivery is unavailable for this location.');
});

test('unsupported and missing destinations fail deterministically', function (?string $destination) {
    Http::preventStrayRequests();
    $rules = app(DeliveryRules::class);

    expect(fn () => $rules->destination($destination ?? ''))
        ->toThrow(DomainException::class, 'Unsupported delivery destination.');
    expect(fn () => $rules->quote(FulfillmentMethod::Delivery, $destination, []))
        ->toThrow(DomainException::class, 'Unsupported delivery destination.');
    Http::assertNothingSent();
})->with([
    'unsupported city' => ['Guihulngan City'],
    'missing city' => [null],
    'blank city' => [''],
    'noncanonical name' => ['Sagay'],
    'wrong case' => ['sagay city'],
]);

test('handling values and priority match each approved profile', function (
    ShippingProfile $profile,
    int $priority,
    string $surcharge,
    int $preparation,
    string $deliveryFee,
    int $etaMinimum,
    int $etaMaximum,
) {
    $rules = app(DeliveryRules::class);
    $product = Product::factory()->make(['category_id' => 1, 'shipping_profile' => $profile]);

    $quote = $rules->quote(FulfillmentMethod::Delivery, 'Bacolod City', [$product]);

    expect($profile->priority())->toBe($priority);
    expect($rules->handling($profile))->toBe(['surcharge' => $surcharge, 'preparation_days' => $preparation]);
    expect($quote)->shipping_profile->toBe($profile->value)
        ->handling_surcharge->toBe($surcharge)
        ->delivery_fee->toBe($deliveryFee)
        ->preparation_days->toBe($preparation)
        ->eta_min_days->toBe($etaMinimum)
        ->eta_max_days->toBe($etaMaximum);
})->with([
    'standard' => [ShippingProfile::Standard, 0, '0.00', 1, '250.00', 2, 3],
    'fragile' => [ShippingProfile::Fragile, 1, '50.00', 2, '300.00', 3, 4],
    'bulky' => [ShippingProfile::Bulky, 2, '100.00', 3, '350.00', 4, 5],
]);

test('mixed products select the highest profile once independent of ordering or repetition', function (array $profiles, string $highest, string $fee, int $etaMinimum, int $etaMaximum) {
    $products = array_map(
        fn (ShippingProfile $profile): Product => Product::factory()->make(['category_id' => 1, 'shipping_profile' => $profile]),
        $profiles,
    );
    $rules = app(DeliveryRules::class);

    $quote = $rules->quote(FulfillmentMethod::Delivery, 'Sagay City', $products);

    expect($rules->highestProfile($products)->value)->toBe($highest);
    expect($quote)->shipping_profile->toBe($highest)
        ->delivery_fee->toBe($fee)
        ->eta_min_days->toBe($etaMinimum)
        ->eta_max_days->toBe($etaMaximum);
})->with([
    'fragile outranks standard' => [[ShippingProfile::Standard, ShippingProfile::Fragile, ShippingProfile::Standard], 'fragile', '130.00', 2, 3],
    'bulky last' => [[ShippingProfile::Standard, ShippingProfile::Fragile, ShippingProfile::Bulky], 'bulky', '180.00', 3, 4],
    'bulky first and repeated' => [[ShippingProfile::Bulky, ShippingProfile::Fragile, ShippingProfile::Bulky, ShippingProfile::Standard], 'bulky', '180.00', 3, 4],
]);

test('persisted mixed carts keep one surcharge when line quantities change', function () {
    $cart = Cart::factory()->create();
    $inventory = Inventory::factory()->state(['quantity' => 10]);
    $products = collect([
        Product::factory()->standard()->has($inventory)->create(),
        Product::factory()->fragile()->has($inventory)->create(),
        Product::factory()->bulky()->has($inventory)->create(),
        Product::factory()->bulky()->has($inventory)->create(),
    ]);
    foreach ($products as $product) {
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => 1]);
    }
    $rules = app(DeliveryRules::class);
    $before = $rules->quote(FulfillmentMethod::Delivery, 'Cadiz City', $cart->items()->with('product')->get()->pluck('product'));
    $cart->items()->update(['quantity' => 5]);

    $after = $rules->quote(FulfillmentMethod::Delivery, 'Cadiz City', $cart->items()->with('product')->get()->pluck('product'));

    expect($after)->toBe($before)
        ->shipping_profile->toBe('bulky')
        ->handling_surcharge->toBe('100.00')
        ->delivery_fee->toBe('220.00')
        ->preparation_days->toBe(3)
        ->eta_min_days->toBe(4)
        ->eta_max_days->toBe(4);
});

test('valid nationwide destinations without configured pricing return a zero fee without an ETA', function () {
    $product = Product::factory()->bulky()->make(['category_id' => 1]);

    $quote = app(DeliveryRules::class)->quote(FulfillmentMethod::Delivery, '1380300000', [$product]);

    expect($quote)
        ->pricing_configured->toBeFalse()
        ->shipping_profile->toBe('bulky')
        ->base_fee->toBe('0.00')
        ->handling_surcharge->toBe('0.00')
        ->delivery_fee->toBe('0.00')
        ->preparation_days->toBeNull()
        ->transit_min_days->toBeNull()
        ->transit_max_days->toBeNull()
        ->eta_min_days->toBeNull()
        ->eta_max_days->toBeNull();
});

test('pickup bypasses destination lookup and product evaluation', function (?string $destination) {
    $products = (function (): Generator {
        throw new RuntimeException('Pickup must not inspect products.');
        yield new Product;
    })();

    expect(app(DeliveryRules::class)->quote(FulfillmentMethod::Pickup, $destination, $products))->toBeNull();
})->with([null, 'Unsupported city', 'Sagay City']);

test('delivery rejects an empty product iterable', function () {
    expect(fn () => app(DeliveryRules::class)->quote(FulfillmentMethod::Delivery, 'Sagay City', []))
        ->toThrow(InvalidArgumentException::class, 'Delivery quotes require at least one product.');
});

test('delivery rejects products whose persisted profile was not loaded', function () {
    $product = Product::factory()->bulky()->create();
    $partiallyLoaded = Product::query()->select('id')->findOrFail($product->id);

    expect(fn () => app(DeliveryRules::class)->quote(FulfillmentMethod::Delivery, 'Sagay City', [$partiallyLoaded]))
        ->toThrow(InvalidArgumentException::class, 'Products must have a loaded shipping profile.');
});

test('configured decimal fees are added at two decimal places independently of global scale', function () {
    config([
        'battlefront.delivery.destinations.Sagay City.base_fee' => '80.15',
        'battlefront.delivery.profiles.fragile.surcharge' => '50.25',
    ]);
    $product = Product::factory()->fragile()->make(['category_id' => 1]);
    $previousScale = bcscale(0);

    try {
        $quote = app(DeliveryRules::class)->quote(FulfillmentMethod::Delivery, 'Sagay City', [$product]);

        expect($quote['delivery_fee'])->toBe('130.40');
    } finally {
        bcscale($previousScale);
    }
});
