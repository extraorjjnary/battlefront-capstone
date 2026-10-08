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
        'assumption_label' => 'Battlefront-configured demo delivery assumptions; not official LBC rates.',
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
