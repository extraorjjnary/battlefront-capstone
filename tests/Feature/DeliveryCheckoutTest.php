<?php

use App\Actions\Order\PlaceCustomerOrder;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Cart\CartService;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** @return array{customer: User, product: Product, stock: Inventory} */
function deliveryCheckoutCart(ShippingProfile $profile = ShippingProfile::Standard): array
{
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'price' => '10.15', 'discount_price' => '9.33', 'shipping_profile' => $profile,
    ]);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);

    return compact('customer', 'product', 'stock');
}

/** @return array<string, mixed> */
function deliveryCheckoutSubmission(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Delivery Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery',
        'delivery_destination' => 'Sagay City',
        'delivery_address' => '12 Example Street, Barangay Poblacion',
        'payment_method' => 'gcash',
        'payment_proof' => UploadedFile::fake()->image('proof.png'),
    ], $overrides);
}

test('web and mobile checkout expose equivalent quotes for all destination fees and profiles', function (ShippingProfile $profile, int $surcharge, int $preparation) {
    $this->seed(BranchSeeder::class);
    $this->travelTo(Carbon::parse('2026-12-30 23:30:00', 'UTC'));
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart($profile);

    $web = $this->actingAs($customer)->get(route('checkout.index'))->assertOk();
    $api = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/checkout')->assertOk();
    $quotes = $api->json('data.delivery_quotes');

    expect($quotes)->toBe($web->inertiaProps('deliveryQuotes'))->toHaveCount(12);
    expect($api->json('data.pickup_quote'))->toBe([
        'product_subtotal' => '18.66', 'delivery_fee' => '0.00', 'total' => '18.66',
    ])->toBe($web->inertiaProps('pickupQuote'));
    foreach ([
        ['Sagay City', 80, 0, 1], ['Escalante City', 100, 1, 1], ['Cadiz City', 120, 1, 1],
        ['Toboso', 140, 1, 1], ['Manapla', 160, 1, 1], ['Calatrava', 180, 1, 1],
        ['Victorias City', 180, 1, 1], ['E.B. Magalona', 200, 1, 2], ['San Carlos City', 220, 1, 2],
        ['Silay City', 220, 1, 2], ['Talisay City', 240, 1, 2], ['Bacolod City', 250, 1, 2],
    ] as $index => [$destination, $base, $minimum, $maximum]) {
        expect($quotes[$index])->destination->toBe($destination)
            ->base_fee->toBe($base.'.00')->handling_surcharge->toBe($surcharge.'.00')
            ->delivery_fee->toBe(($base + $surcharge).'.00')->total->toBe(($base + $surcharge + 18).'.66')
            ->product_subtotal->toBe('18.66')->shipping_profile->toBe($profile->value)
            ->preparation_days->toBe($preparation)->transit_min_days->toBe($minimum)->transit_max_days->toBe($maximum)
            ->eta_min_days->toBe($preparation + $minimum)->eta_max_days->toBe($preparation + $maximum)
            ->carrier->toBe('lbc')->origin_city->toBe('Sagay City')->is_demo->toBeTrue()
            ->eta_anchor_date->toBe('2026-12-30')->eta_timezone->toBe('UTC');
        expect($quotes[$index]['notice'])->toContain('not live LBC quotations or tracking', 'subject to payment verification');
    }
    expect($quotes[0]['estimated_delivery_start'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2026-12-31', ShippingProfile::Fragile => '2027-01-01', ShippingProfile::Bulky => '2027-01-02',
    });
    expect($quotes[0]['estimated_delivery_end'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2027-01-01', ShippingProfile::Fragile => '2027-01-02', ShippingProfile::Bulky => '2027-01-03',
    });
    expect($quotes[11]['estimated_delivery_end'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2027-01-02', ShippingProfile::Fragile => '2027-01-03', ShippingProfile::Bulky => '2027-01-04',
    });
    expect($stock->refresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    Http::assertNothingSent();
})->with([
    'standard' => [ShippingProfile::Standard, 0, 1],
    'fragile' => [ShippingProfile::Fragile, 50, 2],
    'bulky' => [ShippingProfile::Bulky, 100, 3],
]);

test('checkout calendar windows use the configured timezone across leap days', function () {
    $this->seed(BranchSeeder::class);
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2028-02-27 18:00:00', 'UTC'));
    ['customer' => $customer] = deliveryCheckoutCart();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/checkout')->assertOk()
        ->assertJsonPath('data.delivery_quotes.0.eta_anchor_date', '2028-02-28')
        ->assertJsonPath('data.delivery_quotes.0.eta_timezone', 'Asia/Manila')
        ->assertJsonPath('data.delivery_quotes.0.estimated_delivery_start', '2028-02-29')
        ->assertJsonPath('data.delivery_quotes.11.estimated_delivery_end', '2028-03-02');
});

test('delivery placement snapshots each profile through the shared web and mobile boundaries', function (string $channel, ShippingProfile $profile, string $fee, string $total, int $preparation) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart($profile);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission(['payment_method' => 'maya']));
    $order = Order::sole();

    if ($channel === 'web') {
        $response->assertRedirectToRoute('orders.show', $order)->assertSessionHasNoErrors();
    } else {
        $response->assertCreated()->assertJsonPath('data.product_subtotal', '18.66')
            ->assertJsonPath('data.delivery_fee', $fee)->assertJsonPath('data.total', $total)
            ->assertJsonPath('data.delivery_quote.shipping_profile', $profile->value)
            ->assertJsonPath('data.delivery_quote.carrier', 'lbc');
    }
    expect($order)->product_subtotal->toBe('18.66')->delivery_fee->toBe($fee)->total_amount->toBe($total)
        ->shipping_profile->toBe($profile)->delivery_destination->toBe('Sagay City');
    expect($order->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation)->preparation_days->toBe($preparation);
    expect($stock->refresh()->quantity)->toBe(8);
    expect($customer->refresh()->cart)->toBeNull();
    $this->assertDatabaseCount('shipments', 1);
    Storage::disk('local')->assertExists($order->payment_proof_path);
})->with(['web', 'api'])->with([
    'standard' => [ShippingProfile::Standard, '80.00', '98.66', 1],
    'fragile' => [ShippingProfile::Fragile, '130.00', '148.66', 2],
    'bulky' => [ShippingProfile::Bulky, '180.00', '198.66', 3],
]);

test('mixed cart preview and placement charge the highest handling profile only once', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    foreach ([ShippingProfile::Standard, ShippingProfile::Fragile, ShippingProfile::Bulky, ShippingProfile::Bulky] as $profile) {
        $product = Product::factory()->create(['price' => '10.00', 'shipping_profile' => $profile]);
        Inventory::factory()->for($product)->create(['quantity' => 10]);
        app(CartService::class)->add($customer, $product->id, 3);
    }
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get('/api/v1/checkout')->assertOk()
        ->assertJsonPath('data.delivery_quotes.0.product_subtotal', '120.00')
        ->assertJsonPath('data.delivery_quotes.0.handling_surcharge', '100.00')
        ->assertJsonPath('data.delivery_quotes.0.delivery_fee', '180.00')
        ->assertJsonPath('data.delivery_quotes.0.total', '300.00');

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission());

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertCreated();
    expect(Order::sole())->product_subtotal->toBe('120.00')->handling_surcharge->toBe('100.00')
        ->delivery_fee->toBe('180.00')->total_amount->toBe('300.00')->shipping_profile->toBe(ShippingProfile::Bulky);
    expect(Inventory::pluck('quantity')->all())->toBe([7, 7, 7, 7]);
    $this->assertDatabaseCount('shipments', 1);
})->with(['web', 'api']);

test('placement ignores forged quote values and recalculates changed prices profiles fees and ETA', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    ['customer' => $customer, 'product' => $product, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get('/api/v1/checkout')->assertOk()->assertJsonPath('data.delivery_quotes.0.total', '98.66');
    $product->update(['discount_price' => null, 'shipping_profile' => ShippingProfile::Bulky]);
    config([
        'battlefront.delivery.destinations.Sagay City' => ['base_fee' => '80.15', 'transit_min_days' => 2, 'transit_max_days' => 3],
        'battlefront.delivery.profiles.bulky' => ['surcharge' => '100.25', 'preparation_days' => 4],
    ]);
    $payload = deliveryCheckoutSubmission([
        'delivery_fee' => '0.01', 'base_fee' => '0.01', 'delivery_base_fee' => '0.01', 'handling_surcharge' => '0.01',
        'shipping_profile' => 'standard', 'preparation_days' => 0, 'transit_min_days' => 0, 'transit_max_days' => 0,
        'eta_min_days' => 0, 'eta_max_days' => 0, 'eta_anchor_date' => '1900-01-01',
        'estimated_delivery_start' => '1900-01-01', 'estimated_delivery_end' => '1900-01-01',
        'product_subtotal' => '0.01', 'subtotal' => '0.01', 'total' => '0.01', 'total_amount' => '0.01',
        'carrier' => 'fake', 'delivery_quote' => ['total' => '0.01'], 'items' => [['quantity' => 99, 'unit_price' => '0.01']],
    ]);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', $payload);

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertCreated();
    $order = Order::sole();
    expect($order)->product_subtotal->toBe('20.30')->delivery_base_fee->toBe('80.15')->handling_surcharge->toBe('100.25')
        ->delivery_fee->toBe('180.40')->total_amount->toBe('200.70')->shipping_profile->toBe(ShippingProfile::Bulky);
    expect($order->shipment)->carrier->toBe('lbc')->preparation_days->toBe(4)->eta_min_days->toBe(6)->eta_max_days->toBe(7);
    expect($stock->refresh()->quantity)->toBe(8);
    config(['battlefront.delivery.destinations' => [], 'battlefront.delivery.profiles' => [], 'battlefront.delivery.carrier' => 'changed']);
    $product->update(['shipping_profile' => ShippingProfile::Standard, 'price' => '999.00']);

    $detail = $this->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.total', '200.70')->assertJsonPath('data.delivery_quote.base_fee', '80.15')
        ->assertJsonPath('data.delivery_quote.carrier', 'lbc')->assertJsonPath('data.delivery_quote.eta_max_days', 7)
        ->assertJsonMissingPath('data.delivery_quote.estimated_delivery_start')
        ->assertJsonMissingPath('data.payment_proof_path');
    expect($detail->json('data.delivery_quote'))->toBe(
        $this->get(route('orders.show', $order))->assertOk()->inertiaProps('order.delivery_quote'),
    );
    expect($detail->getContent())->not->toContain('payment-proofs/');
})->with(['web', 'api']);

test('delivery rejects missing unsupported noncanonical and nonscalar destinations without side effects', function (string $channel, mixed $destination, string $message) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission(['delivery_destination' => $destination]));

    if ($channel === 'web') {
        $response->assertSessionHasErrors(['delivery_destination' => $message]);
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.delivery_destination', [$message]);
    }
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with([
    'missing' => [null, 'Select a supported delivery destination.'],
    'unsupported' => ['Guihulngan City', 'Select a supported delivery destination.'],
    'wrong case' => ['sagay city', 'Select a supported delivery destination.'],
    'address as destination' => ['12 Example Street, Sagay City', 'Select a supported delivery destination.'],
    'multiple destinations' => [['Sagay City', 'Bacolod City'], 'Select one supported delivery destination.'],
]);

test('pickup prohibits a destination and preserves zero fee placement', function (string $channel) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart(ShippingProfile::Bulky);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $payload = ['recipient_name' => 'Pickup Customer', 'contact_number' => '09171234567', 'fulfillment_method' => 'pickup', 'payment_method' => 'cash'];
    $url = $channel === 'web' ? route('orders.store') : '/api/v1/orders';

    $invalid = $this->post($url, [...$payload, 'delivery_destination' => 'Sagay City']);
    if ($channel === 'web') {
        $invalid->assertSessionHasErrors(['delivery_destination' => 'A delivery destination is not used for pickup orders.']);
    } else {
        $invalid->assertUnprocessable()->assertJsonPath('errors.delivery_destination', ['A delivery destination is not used for pickup orders.']);
    }
    $this->assertDatabaseCount('orders', 0);
    expect($stock->refresh()->quantity)->toBe(10);
    $response = $this->post($url, [...$payload, 'delivery_fee' => '999.99', 'total' => '999.99']);
    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertCreated();

    expect(Order::sole())->product_subtotal->toBe('18.66')->delivery_fee->toBe('0.00')->total_amount->toBe('18.66')
        ->delivery_address->toBeNull()->delivery_destination->toBeNull()->shipment->toBeNull();
    $this->assertDatabaseCount('shipments', 0);
    expect($stock->refresh()->quantity)->toBe(8);
})->with(['web', 'api']);

test('delivery shipment failure rolls back placement and deletes newly uploaded proof', function (string $channel) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    Event::listen('eloquent.creating: '.Shipment::class, fn (): never => throw new RuntimeException('Shipment persistence failed.'));
    config(['app.debug' => false]);

    try {
        $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission())->assertInternalServerError();
    } finally {
        Event::forget('eloquent.creating: '.Shipment::class);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api']);

test('a destination removed before internal placement becomes a validation error with proof cleanup', function () {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $payload = deliveryCheckoutSubmission();
    config(['battlefront.delivery.destinations' => []]);

    try {
        app(PlaceCustomerOrder::class)->execute($customer, $payload, $payload['payment_proof']);
        $this->fail('Unsupported destination placement must fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['delivery_destination' => ['Select a supported delivery destination.']]);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
