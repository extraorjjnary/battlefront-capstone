<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function structuredProfileData(User $user, array $overrides = []): array
{
    return array_replace([
        'name' => $user->name,
        'email' => $user->email,
        'default_delivery_address_action' => 'save',
        'default_delivery_destination' => 'Sagay City',
        'default_delivery_barangay_code' => '1804523018',
        'default_delivery_address_details' => '12 Mabini Street',
    ], $overrides);
}

test('customers save replace and clear a validated structured default address through web and mobile', function (string $channel) {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $url = $channel === 'web' ? route('profile.update') : '/api/v1/profile';

    $saved = $this->patch($url, structuredProfileData($customer, [
        'default_delivery_address' => 'Untrusted assembled address',
        'default_delivery_address_components' => ['city_code' => 'untrusted'],
    ]));
    $channel === 'web' ? $saved->assertSessionHasNoErrors() : $saved->assertOk();
    expect($customer->refresh()->default_delivery_address)->toBe('12 Mabini Street, Poblacion I, Sagay City, Negros Occidental');
    expect($customer->default_delivery_address_components)->toBe([
        'destination' => 'Sagay City', 'city_code' => '1804523000',
        'barangay_code' => '1804523018', 'details' => '12 Mabini Street',
    ]);

    $updated = $this->patch($url, structuredProfileData($customer, [
        'default_delivery_destination' => 'Bacolod City',
        'default_delivery_barangay_code' => '1830200008',
        'default_delivery_address_details' => '45 Lizares Street',
    ]));
    $channel === 'web' ? $updated->assertSessionHasNoErrors() : $updated->assertOk();
    expect($customer->refresh()->default_delivery_address)->toBe('45 Lizares Street, Barangay 13, Bacolod City');
    expect($customer->default_delivery_address_components)->toBe([
        'destination' => 'Bacolod City', 'city_code' => '1830200000',
        'barangay_code' => '1830200008', 'details' => '45 Lizares Street',
    ]);

    $cleared = $this->patch($url, structuredProfileData($customer, [
        'default_delivery_address_action' => 'clear',
    ]));
    $channel === 'web' ? $cleared->assertSessionHasNoErrors() : $cleared->assertOk();
    expect($customer->refresh()->default_delivery_address)->toBeNull();
    expect($customer->default_delivery_address_components)->toBeNull();
})->with(['web', 'api']);

test('saving an incomplete or invalid default leaves all profile information unchanged', function (string $channel, array $overrides, string $field, string $message) {
    $customer = User::factory()->customer()->create(['default_delivery_address' => 'Existing ambiguous address']);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $response = $this->patch($channel === 'web' ? route('profile.update') : '/api/v1/profile', structuredProfileData($customer, [
        'name' => 'Unsaved name change',
        ...$overrides,
    ]));

    if ($channel === 'web') {
        $response->assertSessionHasErrors([$field => $message]);
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.'.$field, [$message]);
    }
    expect($customer->refresh()->default_delivery_address)->toBe('Existing ambiguous address');
    expect($customer->default_delivery_address_components)->toBeNull();
    expect($customer->name)->not->toBe('Unsaved name change');
})->with(['web', 'api'])->with([
    'missing city' => [['default_delivery_destination' => null], 'default_delivery_destination', 'Select a supported delivery destination.'],
    'unsupported city' => [['default_delivery_destination' => 'Guihulngan City'], 'default_delivery_destination', 'Select a supported delivery destination.'],
    'city array' => [['default_delivery_destination' => ['Sagay City']], 'default_delivery_destination', 'Select one supported delivery destination.'],
    'missing barangay' => [['default_delivery_barangay_code' => null], 'default_delivery_barangay_code', 'Select a barangay.'],
    'numeric barangay' => [['default_delivery_barangay_code' => 1804523018], 'default_delivery_barangay_code', 'Select a valid barangay.'],
    'mismatched barangay' => [['default_delivery_barangay_code' => '1804524001'], 'default_delivery_barangay_code', 'Select a barangay belonging to the selected city or municipality.'],
    'invalid barangay' => [['default_delivery_barangay_code' => 'not-a-code'], 'default_delivery_barangay_code', 'Select a valid barangay.'],
    'missing details' => [['default_delivery_address_details' => null], 'default_delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'blank details' => [['default_delivery_address_details' => '  '], 'default_delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'array details' => [['default_delivery_address_details' => ['Street']], 'default_delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'details too long' => [['default_delivery_address_details' => str_repeat('a', 256)], 'default_delivery_address_details', 'Delivery address details may not exceed 255 characters.'],
    'assembled address too long' => [['default_delivery_address_details' => str_repeat('a', 255)], 'default_delivery_address_details', 'The complete delivery address may not exceed 255 characters. Shorten the house, building, street, or landmark details.'],
    'invalid action' => [['default_delivery_address_action' => 'discard'], 'default_delivery_address_action', 'Choose whether to keep, replace, or clear the saved delivery address.'],
]);

test('default address remains optional and keeping it allows unrelated profile changes', function (?string $legacyAddress) {
    $customer = User::factory()->customer()->create(['default_delivery_address' => $legacyAddress]);

    $this->actingAs($customer)->patch(route('profile.update'), structuredProfileData($customer, [
        'name' => 'Updated customer',
        'default_delivery_address_action' => 'keep',
        'default_delivery_destination' => 'Sagay City',
        'default_delivery_barangay_code' => null,
        'default_delivery_address_details' => '',
        'personalized_recommendations_enabled' => false,
        'search_recommendations_enabled' => false,
        'product_view_recommendations_enabled' => false,
    ]))->assertSessionHasNoErrors();

    expect($customer->refresh()->default_delivery_address)->toBe($legacyAddress);
    expect($customer->default_delivery_address_components)->toBeNull();
    expect($customer->name)->toBe('Updated customer');
    expect($customer->personalized_recommendations_enabled)->toBeFalse();
    expect($customer->search_recommendations_enabled)->toBeFalse();
    expect($customer->product_view_recommendations_enabled)->toBeFalse();
})->with([null, 'Existing ambiguous address', '12 Mabini Street, Sagay City']);

test('keeping or omitting address changes preserves saved structured components', function (string $channel, bool $explicitKeep) {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $url = $channel === 'web' ? route('profile.update') : '/api/v1/profile';
    $this->patch($url, structuredProfileData($customer));
    $address = $customer->refresh()->default_delivery_address;
    $components = $customer->default_delivery_address_components;
    $data = ['name' => 'Updated customer', 'email' => $customer->email];

    if ($explicitKeep) {
        $data += ['default_delivery_address_action' => 'keep', 'default_delivery_destination' => null];
    }

    $response = $this->patch($url, $data);
    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk();
    expect($customer->refresh()->name)->toBe('Updated customer');
    expect($customer->default_delivery_address)->toBe($address);
    expect($customer->default_delivery_address_components)->toBe($components);
})->with(['web', 'api'])->with([true, false]);

test('partial mobile structured inputs require complete fields instead of accepting legacy text', function () {
    $customer = User::factory()->customer()->create(['default_delivery_address' => 'Saved legacy address']);

    $this->withToken($customer->createToken('Phone')->plainTextToken)->patchJson('/api/v1/profile', [
        'name' => $customer->name, 'email' => $customer->email,
        'default_delivery_address' => 'Unvalidated replacement',
        'default_delivery_barangay_code' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'default_delivery_destination', 'default_delivery_barangay_code', 'default_delivery_address_details',
    ]);

    expect($customer->refresh()->default_delivery_address)->toBe('Saved legacy address');
    expect($customer->default_delivery_address_components)->toBeNull();
});

test('legacy addresses are conservatively prefilled without changing their stored text', function (string $address, array $prefill) {
    config(['inertia.ssr.enabled' => false]);
    $customer = User::factory()->customer()->create(['default_delivery_address' => $address]);

    $this->actingAs($customer)->get(route('profile.edit'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('defaultDeliveryAddress', $address)
            ->where('defaultDeliveryAddressPrefill', $prefill)
            ->has('deliveryLocations', 12)
            ->missing('auth.user.default_delivery_address_components'));

    expect($customer->refresh()->default_delivery_address)->toBe($address);
    expect($customer->default_delivery_address_components)->toBeNull();
})->with([
    'complete recognizable suffix' => ['12 Mabini Street, Brgy. Poblacion I, Sagay City, Negros Occidental', [
        'delivery_destination' => 'Sagay City', 'delivery_barangay_code' => '1804523018', 'delivery_address_details' => '12 Mabini Street',
    ]],
    'ambiguous barangay' => ['12 Mabini Street, Brgy. Poblacion, Sagay City', [
        'delivery_destination' => 'Sagay City', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
    ]],
    'unstructured text' => ['Existing ambiguous address', [
        'delivery_destination' => '', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
    ]],
]);

test('structured defaults directly prefill checkout and checkout edits stay specific to the order', function () {
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->patch(route('profile.update'), structuredProfileData($customer, [
        'default_delivery_address_details' => 'Unit 2, Lopez Building, Mabini Street',
    ]))->assertSessionHasNoErrors();
    $savedComponents = $customer->refresh()->default_delivery_address_components;
    $savedAddress = $customer->default_delivery_address;
    $product = Product::factory()->standard()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = app(CartService::class)->add($customer, $product->id, 1);
    $query = ['cart_item_ids' => [$item->id]];
    $expectedPrefill = [
        'delivery_destination' => 'Sagay City',
        'delivery_barangay_code' => '1804523018',
        'delivery_address_details' => 'Unit 2, Lopez Building, Mabini Street',
    ];

    $this->get(route('checkout.index', $query))->assertOk()
        ->assertInertia(fn ($page) => $page->where('deliveryAddressPrefill', $expectedPrefill));
    $this->withToken($customer->createToken('Phone')->plainTextToken)->get('/api/v1/checkout?'.http_build_query($query))
        ->assertOk()->assertJsonPath('data.delivery_address_prefill', $expectedPrefill);

    $this->post(route('orders.store'), [
        'cart_item_ids' => [$item->id],
        'recipient_name' => $customer->name, 'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery', 'payment_method' => 'online',
        'payment_channel' => 'hosted_checkout', 'hosted_payment_method' => 'qrph',
        'checkout_submission_key' => (string) Str::uuid(),
        'delivery_destination' => 'Bacolod City', 'delivery_barangay_code' => '1830200008',
        'delivery_address_details' => '99 Lizares Street',
    ])->assertSessionHasNoErrors();

    expect(Order::sole()->delivery_address)->toBe('99 Lizares Street, Barangay 13, Bacolod City');
    expect($customer->refresh()->default_delivery_address)->toBe($savedAddress);
    expect($customer->default_delivery_address_components)->toBe($savedComponents);
    expect($stock->refresh()->quantity)->toBe(4);
});

test('legacy mobile profile address updates remain compatible and invalidate stale components', function () {
    $customer = User::factory()->customer()->create();
    $token = $customer->createToken('Phone')->plainTextToken;
    $this->withToken($token)->patchJson('/api/v1/profile', structuredProfileData($customer))->assertOk();
    $this->patchJson('/api/v1/profile', [
        'name' => $customer->name, 'email' => $customer->email,
        'default_delivery_address' => 'Legacy unstructured address',
    ])->assertOk()->assertJsonPath('data.default_delivery_address', 'Legacy unstructured address');

    expect($customer->refresh()->default_delivery_address_components)->toBeNull();
    $this->patchJson('/api/v1/profile', [
        'name' => $customer->name, 'email' => $customer->email,
        'default_delivery_address' => '45 Lizares Street, Barangay 13, Bacolod City',
    ])->assertOk();
    expect($customer->refresh()->default_delivery_address_components)->toBe([
        'destination' => 'Bacolod City', 'city_code' => '1830200000',
        'barangay_code' => '1830200008', 'details' => '45 Lizares Street',
    ]);
    $this->patchJson('/api/v1/profile', [
        'name' => $customer->name, 'email' => $customer->email,
        'default_delivery_address' => null,
    ])->assertOk();
    expect($customer->refresh()->default_delivery_address)->toBeNull();
    expect($customer->default_delivery_address_components)->toBeNull();
});

test('web raw address submissions cannot bypass structured validation', function () {
    $customer = User::factory()->customer()->create(['default_delivery_address' => 'Saved legacy address']);

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name, 'email' => $customer->email,
        'default_delivery_address' => 'Unvalidated replacement',
    ])->assertSessionHasErrors([
        'default_delivery_destination', 'default_delivery_barangay_code', 'default_delivery_address_details',
    ]);

    expect($customer->refresh()->default_delivery_address)->toBe('Saved legacy address');
});

test('administrators cannot persist or expose structured default addresses', function () {
    config(['inertia.ssr.enabled' => false]);
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator)->patch(route('profile.update'), structuredProfileData($administrator, [
        'default_delivery_address_components' => ['city_code' => 'untrusted'],
    ]))->assertSessionHasNoErrors();
    expect($administrator->refresh()->default_delivery_address)->toBeNull();
    expect($administrator->default_delivery_address_components)->toBeNull();
    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('canManageDefaultDeliveryAddress', false)
        ->where('deliveryLocations', [])
        ->where('defaultDeliveryAddress', null)
        ->where('defaultDeliveryAddressPrefill', [
            'delivery_destination' => '', 'delivery_barangay_code' => '', 'delivery_address_details' => '',
        ])
        ->missing('auth.user.default_delivery_address_components'));
});

test('the additive migration preserves legacy text and refuses to discard saved components', function () {
    $customer = User::factory()->customer()->create(['default_delivery_address' => 'Existing legacy address']);
    $migration = require database_path('migrations/2026_10_12_141949_add_default_delivery_address_components_to_users_table.php');
    $migration->down();
    $migration->up();
    expect($customer->refresh()->default_delivery_address)->toBe('Existing legacy address');
    expect($customer->default_delivery_address_components)->toBeNull();

    $this->actingAs($customer)->patch(route('profile.update'), structuredProfileData($customer))->assertSessionHasNoErrors();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot remove saved structured delivery addresses. Use a forward migration instead.');
    expect($customer->refresh()->default_delivery_address_components['city_code'])->toBe('1804523000');
});
