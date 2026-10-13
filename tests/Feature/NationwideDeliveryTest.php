<?php

use App\Actions\Product\UpdateProduct;
use App\Enums\ShippingProfile;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\CatalogShippingClassification;
use App\Services\Order\DeliveryRules;
use App\Services\Order\PsgcDeliveryLocations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** @return array{User, array<string, mixed>, Inventory} */
function nationwideCheckout(string $profile = 'fragile'): array
{
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['shipping_profile' => $profile, 'price' => '100.00', 'discount_price' => null]);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);

    return [$customer, [
        'cart_item_ids' => $customer->cart->items->modelKeys(), 'recipient_name' => 'Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery', 'payment_method' => 'cod', 'delivery_region_code' => '1800000000',
        'delivery_province_code' => '1804500000', 'delivery_city_code' => '1804523000',
        'delivery_barangay_code' => '1804523018', 'delivery_address_details' => '12 Mabini Street',
    ], $inventory];
}

test('nationwide geography has complete unique parent chains without external requests', function () {
    Http::preventStrayRequests();
    $locations = app(PsgcDeliveryLocations::class);
    $data = $locations->geography();
    expect($data['regions'])->toHaveCount(18);
    expect($data['provinces'])->toHaveCount(82);
    expect($data['cities'])->toHaveCount(1642);
    $codes = [];
    foreach ($data['cities'] as $city) {
        expect($locations->hierarchyErrors($city['region_code'], $city['province_code'], $city['code']))->toBe([]);
        $barangays = $locations->cityBarangays($city['code']);
        expect($barangays)->not->toBeEmpty();
        foreach ($barangays as $barangay) {
            expect($barangay['code'])->toBeString()->toMatch('/^\d{10}$/');
            expect($barangay['city_code'])->toBe($city['code']);
            expect(isset($codes[$barangay['code']]))->toBeFalse();
            $codes[$barangay['code']] = true;
        }
    }
    expect($codes)->toHaveCount(42010);
    expect($locations->city('1830200000')['province_code'])->toBeNull();
    expect($locations->city('1830200000')['region_code'])->toBe('1800000000');
    expect($locations->lookup('provinces', '1800000000'))->toHaveCount(3);
    expect($locations->lookup('provinces', '1300000000'))->toBe([]);
    expect($locations->lookup('cities', '1300000000'))->toHaveCount(17);
    expect($locations->cityBarangays('1380600000'))->toHaveCount(897);
    Http::assertNothingSent();
});

test('Negros Occidental expansion covers exactly the remaining province localities and barangays', function () {
    $locations = app(PsgcDeliveryLocations::class);
    $provinceLocalities = array_values(array_filter(
        $locations->geography()['cities'],
        fn (array $city): bool => $city['province_code'] === '1804500000',
    ));
    $newCityCodes = array_keys(config('battlefront.delivery.negros_occidental_distance_proxies'));
    $barangayCount = 0;

    foreach ($newCityCodes as $cityCode) {
        expect($locations->city($cityCode)['province_code'])->toBe('1804500000');
        $barangayCount += count($locations->cityBarangays($cityCode));
    }

    expect($provinceLocalities)->toHaveCount(31);
    expect($newCityCodes)->toHaveCount(20);
    expect($barangayCount)->toBe(362);
    expect($locations->city('1830200000')['province_code'])->toBeNull()
        ->and($locations->city('1830200000')['city_class'])->toBe('HUC');
});

test('local lookup endpoints return matching bounded web and mobile results', function () {
    $customer = User::factory()->customer()->create();
    $params = ['level' => 'barangays', 'parent_code' => '1804523000'];
    $web = $this->actingAs($customer)->getJson(route('delivery-locations.index', $params))->assertOk();
    $api = $this->withToken($customer->createToken('Phone')->plainTextToken)->getJson('/api/v1/delivery-locations?'.http_build_query($params))->assertOk();
    expect($api->json('data'))->toBe($web->json('data'));
    $api->assertJsonCount(25, 'data.locations')->assertJsonPath('data.version', PsgcDeliveryLocations::VERSION);
    $this->getJson(route('delivery-locations.index', ['level' => 'barangays', 'parent_code' => '../invalid']))->assertUnprocessable();
    $this->getJson(route('delivery-locations.index', ['level' => 'provinces', 'parent_code' => '1804500000']))->assertUnprocessable()->assertJsonValidationErrors('parent_code');
});

test('zones choose city then province then region and explicit exclusions fail closed', function () {
    $rules = app(DeliveryRules::class);
    $zone = ['base_fee' => '10.00', 'transit_min_days' => 1, 'transit_max_days' => 3, 'payment_methods' => ['gcash']];
    config(['battlefront.delivery.zones.regions.1800000000' => $zone]);
    expect($rules->zone('1804523000')['base_fee'])->toBe('80.00');
    config(['battlefront.delivery.zones.cities' => []]);
    config(['battlefront.delivery.zones.provinces.1804500000' => [...$zone, 'base_fee' => '20.00']]);
    expect($rules->zone('1804523000')['base_fee'])->toBe('20.00');
    expect($rules->zone('1830200000')['base_fee'])->toBe('10.00');
    config(['battlefront.delivery.zones.cities.1804523000' => ['enabled' => false]]);
    expect(fn () => $rules->zone('1804523000'))->toThrow(DomainException::class, 'Delivery is unavailable');
    config(['battlefront.delivery.zones.cities.1804523000' => ['base_fee' => '0.00']]);
    expect(fn () => $rules->zone('1804523000'))->toThrow(DomainException::class, 'Delivery is unavailable');
});

test('quotes share configured estimates and allow subtotal-only orders without invented delivery details', function () {
    [$customer, $data] = nationwideCheckout();
    $query = ['cart_item_ids' => $data['cart_item_ids'], 'delivery_city_code' => '1804523000'];
    $web = $this->actingAs($customer)->getJson(route('checkout.delivery-quote.show', $query))->assertOk();
    $api = $this->withToken($customer->createToken('Phone')->plainTextToken)->getJson('/api/v1/checkout/delivery-quote?'.http_build_query($query))->assertOk();
    expect($api->json('data'))->toBe($web->json('data'));
    $api->assertJsonPath('data.quote.delivery_fee', '130.00')
        ->assertJsonPath('data.quote.total', '330.00')
        ->assertJsonPath('data.quote.zone.rule_id', 'cities:1804523000')
        ->assertJsonPath('data.quote.zone.rules_version', 'nationwide-demo-v4')
        ->assertJsonPath('data.quote.zone.city_code', '1804523000')
        ->assertJsonMissingPath('data.quote.zone.base_fee');
    $this->getJson(route('checkout.delivery-quote.show', [...$query, 'delivery_city_code' => '1380300000']))
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.quote.pricing_configured', false)
        ->assertJsonPath('data.quote.delivery_fee', '0.00')
        ->assertJsonPath('data.quote.total', '200.00')
        ->assertJsonPath('data.quote.carrier', null)
        ->assertJsonPath('data.quote.estimated_delivery_start', null)
        ->assertJsonPath('data.quote.zone.rule_id', 'unconfigured:1380300000')
        ->assertJsonPath('data.quote.order_placement_allowed', true)
        ->assertJsonPath('data.quote.payment_methods', ['gcash', 'maya', 'cod'])
        ->assertJsonPath('data.quote.notice', config('battlefront.delivery.unconfigured_route.notice'));
    $this->actingAs(User::factory()->customer()->create())->getJson(route('checkout.delivery-quote.show', $query))->assertUnprocessable()->assertJsonValidationErrors('cart');
    $this->assertDatabaseCount('orders', 0);
});

test('nationwide checkout rejects tampered parents without reserving inventory', function (array $change, string $error) {
    [$customer, $data, $inventory] = nationwideCheckout();
    $this->actingAs($customer)->postJson(route('orders.store'), [...$data, ...$change])->assertUnprocessable()->assertJsonValidationErrors($error);
    expect($inventory->refresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('orders', 0);
})->with([
    'region' => [['delivery_region_code' => '1300000000'], 'delivery_region_code'],
    'province' => [['delivery_province_code' => '1804600000'], 'delivery_province_code'],
    'barangay' => [['delivery_barangay_code' => '1804524001'], 'delivery_barangay_code'],
    'unknown city' => [['delivery_city_code' => '9999999999'], 'delivery_city_code'],
    'conflicting alias' => [['delivery_destination' => 'Bacolod City'], 'delivery_destination'],
]);

test('unconfigured nationwide destinations allow subtotal-only orders without automatic shipments', function (string $channel, string $paymentMethod) {
    Storage::fake('local');
    [$customer, $data, $inventory] = nationwideCheckout();
    $barangay = app(PsgcDeliveryLocations::class)->cityBarangays('1380300000')[0]['code'];
    $data = [...$data,
        'delivery_region_code' => '1300000000', 'delivery_province_code' => null,
        'delivery_city_code' => '1380300000', 'delivery_barangay_code' => $barangay,
        'payment_method' => $paymentMethod,
    ];
    if ($paymentMethod !== 'cod') {
        $data['payment_proof'] = UploadedFile::fake()->image('proof.png');
    }
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', $data);

    if ($channel === 'web') {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertCreated()
            ->assertJsonPath('data.product_subtotal', '200.00')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.total', '200.00');
    }
    $order = Order::sole();
    if ($channel === 'web') {
        $response->assertRedirectToRoute('orders.show', $order);
    }

    expect($order->delivery_quote_metadata['pricing_status'])->toBe('not_configured')
        ->and($order->delivery_quote_metadata['payment_methods'])->toBe(['gcash', 'maya', 'cod'])
        ->and($order->delivery_quote_metadata['payment_assumption'])->toBe(config("battlefront.delivery.unconfigured_route.payment_assumptions.{$paymentMethod}"));
    expect($order)->delivery_destination->toBe('City of Makati')
        ->product_subtotal->toBe('200.00')
        ->delivery_fee->toBe('0.00')
        ->total_amount->toBe('200.00')
        ->delivery_base_fee->toBe('0.00')
        ->shipping_profile->toBe(ShippingProfile::Fragile)
        ->handling_surcharge->toBe('0.00')
        ->delivery_origin_city->toBe('Sagay City')
        ->delivery_is_demo->toBeFalse()
        ->shipment->toBeNull();
    expect($inventory->refresh()->quantity)->toBe(8)
        ->and($customer->refresh()->cart)->toBeNull();
    $this->assertDatabaseCount('shipments', 0);
    if ($paymentMethod === 'cod') {
        expect(Storage::disk('local')->allFiles())->toBe([]);
    } else {
        Storage::disk('local')->assertExists($order->payment_proof_path);
    }
})->with(['web', 'api'])->with(['gcash', 'maya', 'cod']);

test('wallets and COD snapshot accepted fees and assumptions without trusting client prices', function (string $method, string $channel) {
    Storage::fake('local');
    [$customer, $data, $inventory] = nationwideCheckout();
    $data = [...$data, 'payment_method' => $method, 'delivery_fee' => '0.00', 'total_amount' => '1.00', 'shipping_profile' => 'standard'];
    if ($method !== 'cod') {
        $data['payment_proof'] = UploadedFile::fake()->image('proof.png');
    }
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', $data);
    $channel === 'web' ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertCreated();
    $order = Order::sole();
    expect($order->delivery_fee)->toBe('130.00');
    expect($order->product_subtotal)->toBe('200.00');
    expect($order->total_amount)->toBe('330.00');
    expect($order->delivery_destination)->toBe('Sagay City');
    expect($order->delivery_quote_metadata['city_code'])->toBe('1804523000');
    expect($order->delivery_quote_metadata['payment_assumption'])->toBe(config('battlefront.delivery.payment_assumptions.'.$method));
    expect($inventory->refresh()->quantity)->toBe(8);
    config(['battlefront.delivery.zones.cities' => [], 'battlefront.delivery.profiles.fragile.surcharge' => '999.00']);
    expect($order->fresh()->delivery_fee)->toBe('130.00');
    expect(fn () => $order->update(['delivery_quote_metadata' => []]))->toThrow(DomainException::class);
})->with(['gcash', 'maya', 'cod'])->with(['web', 'api']);

test('route payment eligibility is enforced for delivery', function () {
    [$customer, $data] = nationwideCheckout();
    config(['battlefront.delivery.zones.cities.1804523000.payment_methods' => ['gcash', 'maya']]);
    $this->actingAs($customer)->postJson(route('orders.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    $this->assertDatabaseCount('orders', 0);
});

test('customers can save unpriced nationwide defaults while incomplete hierarchy is rejected', function () {
    $customer = User::factory()->customer()->create();
    $barangay = app(PsgcDeliveryLocations::class)->cityBarangays('1380300000')[0]['code'];
    $data = ['name' => $customer->name, 'email' => $customer->email, 'default_delivery_address_action' => 'save',
        'default_delivery_region_code' => '1300000000', 'default_delivery_province_code' => null,
        'default_delivery_city_code' => '1380300000', 'default_delivery_barangay_code' => $barangay, 'default_delivery_address_details' => 'Unit 2'];
    $this->actingAs($customer)->patch(route('profile.update'), $data)->assertSessionHasNoErrors();
    expect($customer->fresh()->default_delivery_address)->toContain('City of Makati');
    expect(app(PsgcDeliveryLocations::class)->prefill($customer->fresh()->default_delivery_address, $customer->fresh()->default_delivery_address_components)['delivery_destination'])->toBe('1380300000');
    $this->patchJson(route('profile.update'), [...$data, 'default_delivery_region_code' => '1800000000'])->assertUnprocessable()->assertJsonValidationErrors('default_delivery_region_code');
});

test('mobile profile accepts the same nationwide hierarchy for only the authenticated customer', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create(['default_delivery_address' => 'Untouched legacy address']);
    $barangay = app(PsgcDeliveryLocations::class)->cityBarangays('1380300000')[0]['code'];
    $token = $customer->createToken('Phone')->plainTextToken;
    $this->withToken($token)->patchJson('/api/v1/profile', [
        'name' => $customer->name,
        'email' => $customer->email,
        'default_delivery_address_action' => 'save',
        'default_delivery_region_code' => '1300000000',
        'default_delivery_province_code' => null,
        'default_delivery_city_code' => '1380300000',
        'default_delivery_barangay_code' => $barangay,
        'default_delivery_address_details' => 'Unit 2',
    ])->assertOk()->assertJsonPath('data.default_delivery_address', fn (string $address): bool => str_contains($address, 'City of Makati'));
    expect($customer->fresh()->default_delivery_address_components['city_code'])->toBe('1380300000');
    expect($otherCustomer->fresh()->default_delivery_address)->toBe('Untouched legacy address');
});

test('reviewed catalog mapping preserves accessories manual choices and reapplication', function () {
    $mapping = json_decode(file_get_contents(resource_path('data/catalog-shipping-profiles.v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($mapping['products'])->toHaveCount(654);
    $service = app(CatalogShippingClassification::class);
    foreach (['10819' => ShippingProfile::Bulky, '20239439' => ShippingProfile::Fragile, '10839' => ShippingProfile::Standard] as $code => $profile) {
        $row = collect($mapping['products'])->firstWhere('product_code', (string) $code);
        $category = Category::factory()->create(['name' => $row['category']]);
        $product = Product::factory()->for($category)->create(['product_code' => $row['product_code'], 'name' => $row['name'], 'is_catalog_imported' => true]);
        expect($service->apply($product))->toBeTrue();
        expect($product->refresh()->shipping_profile)->toBe($profile);
        expect($service->apply($product))->toBeFalse();
        app(UpdateProduct::class)->execute($product, ['shipping_profile' => 'standard', 'shipping_profile_reviewed' => true], [], null);
        expect($product->refresh()->shipping_profile_source)->toBe('manual');
        expect($service->apply($product))->toBeFalse();
    }
});

test('shipping metadata migration preserves historical orders and refuses destructive rollback', function () {
    $order = Order::factory()->create(['fulfillment_method' => 'delivery', 'delivery_address' => 'Uncertain original address', 'total_amount' => '123.45']);
    $product = Product::factory()->create();
    $migration = require database_path('migrations/2026_10_13_071534_add_shipping_review_and_quote_metadata.php');
    $migration->down();
    $before = (array) DB::table('orders')->where('id', $order->id)->first();
    $migration->up();
    $after = (array) DB::table('orders')->where('id', $order->id)->first();
    expect(array_intersect_key($after, $before))->toBe($before);
    expect($order->refresh()->delivery_quote_metadata)->toBeNull();
    expect($product->refresh()->shipping_profile_source)->toBeNull();
    $product->shipping_profile_source = 'manual';
    $product->save();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard shipping review');
});

test('catalog classification command previews then applies clear assignments and leaves uncertain products for review', function () {
    $mapping = json_decode(file_get_contents(resource_path('data/catalog-shipping-profiles.v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $rows = collect($mapping['products']);
    $clear = $rows->firstWhere('profile', 'fragile');
    $uncertain = $rows->firstWhere('profile', null);
    $products = [];
    foreach ([$clear, $uncertain] as $row) {
        $category = Category::factory()->create(['name' => $row['category']]);
        $products[] = Product::factory()->for($category)->create(['product_code' => $row['product_code'], 'name' => $row['name'], 'is_catalog_imported' => true]);
    }
    $this->artisan('catalog:classify-shipping')->expectsOutputToContain('Applied: 0; requiring review: 1.')->assertSuccessful();
    expect($products[0]->refresh()->shipping_profile)->toBe(ShippingProfile::Standard);
    $this->artisan('catalog:classify-shipping', ['--apply' => true])->expectsOutputToContain('Applied: 1; requiring review: 1.')->assertSuccessful();
    expect($products[0]->refresh()->shipping_profile)->toBe(ShippingProfile::Fragile);
    expect($products[1]->refresh()->shipping_profile_source)->toBeNull();
});
