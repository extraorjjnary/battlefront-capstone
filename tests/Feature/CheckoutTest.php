<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from checkout', function (string $method) {
    $response = $method === 'get'
        ? $this->get(route('checkout.index'))
        : $this->post(route('orders.store'));

    $response->assertRedirectToRoute('login');
})->with(['get', 'post']);

test('administrators are forbidden from checkout', function (string $method) {
    $administrator = User::factory()->administrator()->create();

    $response = $method === 'get'
        ? $this->actingAs($administrator)->get(route('checkout.index'))
        : $this->actingAs($administrator)->post(route('orders.store'));

    $response->assertForbidden();
})->with(['get', 'post']);

test('customers with an empty cart return to the cart', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('checkout.index'))
        ->assertRedirectToRoute('cart.index')
        ->assertInertiaFlash(
            'toast.message',
            'Add at least one available product before checking out.',
        );
});

test('checkout validation rejects a cart that is empty at submission time', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => 'cash',
        ])
        ->assertSessionHasErrors([
            'cart' => 'Add at least one available product before placing an order.',
        ]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('checkout renders current customer items, pickup location, and supported options', function () {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create(['name' => 'Alex Customer']);
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'name' => 'Battlefront Processor',
        'brand' => 'AMD',
        'price' => '12000.00',
    ]);
    $otherProduct = Product::factory()->create(['price' => '90000.00']);
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    Inventory::factory()->for($otherProduct)->create(['quantity' => 5]);
    $cartService = new CartService;
    $item = $cartService->add($customer, $product->id, 2);
    $cartService->add($otherCustomer, $otherProduct->id, 1);

    $response = $this->actingAs($customer)->get(route('checkout.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Checkout/Index')
        ->where('customer.name', 'Alex Customer')
        ->where('pickupLocation', [
            'name' => 'Battlefront Computer Trading — Sagay City',
            'address' => 'A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122',
            'contact_number' => '0938 647 6046',
            'operating_hours' => '8:00 AM–6:00 PM',
        ])
        ->has('cart.items', 1)
        ->where('cart.items.0.id', $item->id)
        ->where('cart.items.0.product.name', 'Battlefront Processor')
        ->where('cart.items.0.unit_price', '12000.00')
        ->where('cart.items.0.line_total', '24000.00')
        ->where('cart.total', '24000.00')
        ->where('fulfillmentMethods', [
            ['value' => 'pickup', 'label' => 'Pickup'],
            ['value' => 'delivery', 'label' => 'Delivery'],
        ])
        ->where('paymentMethods', [
            [
                'value' => 'cash',
                'label' => 'Cash',
                'requires_proof' => false,
                'payment_account' => null,
                'available_for' => ['pickup'],
            ],
            [
                'value' => 'card_at_store',
                'label' => 'Card at store',
                'requires_proof' => false,
                'payment_account' => null,
                'available_for' => ['pickup'],
            ],
            [
                'value' => 'gcash',
                'label' => 'GCash',
                'requires_proof' => true,
                'payment_account' => [
                    'account_name' => 'Battlefront Demo GCash Account',
                    'account_number' => '09XX XXX XXXX',
                    'is_demo' => true,
                ],
                'available_for' => ['pickup', 'delivery'],
            ],
            [
                'value' => 'maya',
                'label' => 'Maya',
                'requires_proof' => true,
                'payment_account' => [
                    'account_name' => 'Battlefront Demo Maya Account',
                    'account_number' => '09XX XXX XXXX',
                    'is_demo' => true,
                ],
                'available_for' => ['pickup', 'delivery'],
            ],
        ]));

    expect(collect($response->inertiaProps('cart.items'))->pluck('product.id'))
        ->not->toContain($otherProduct->id);
});

test('checkout provides the saved default delivery address for prefill', function () {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->get(route('checkout.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'customer.default_delivery_address',
                '12 Mabini Street, Sagay City',
            ));
});

test('checkout uses separately configured receiving details for each wallet', function () {
    $this->seed(BranchSeeder::class);
    config()->set('battlefront.payment_accounts.gcash', [
        'account_name' => 'Configured GCash Receiver',
        'account_number' => '0917 111 2222',
        'is_demo' => false,
    ]);
    config()->set('battlefront.payment_accounts.maya', [
        'account_name' => 'Configured Maya Receiver',
        'account_number' => '0998 333 4444',
        'is_demo' => false,
    ]);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $response = $this->actingAs($customer)->get(route('checkout.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('paymentMethods.0.payment_account', null)
        ->where('paymentMethods.1.payment_account', null)
        ->where('paymentMethods.2.payment_account', [
            'account_name' => 'Configured GCash Receiver',
            'account_number' => '0917 111 2222',
            'is_demo' => false,
        ])
        ->where('paymentMethods.3.payment_account', [
            'account_name' => 'Configured Maya Receiver',
            'account_number' => '0998 333 4444',
            'is_demo' => false,
        ]));
});

test('checkout rejects required and invalid fields with clear messages', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'))
        ->assertRedirect(route('checkout.index'))
        ->assertSessionHasErrors([
            'recipient_name' => 'Enter the recipient name.',
            'contact_number' => 'Enter a contact number.',
            'fulfillment_method' => 'Select pickup or delivery.',
            'payment_method' => 'Select a payment method.',
        ]);
});

test('delivery requires an address', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'delivery',
            'payment_method' => 'gcash',
            'payment_proof' => UploadedFile::fake()->image('proof.png'),
        ])
        ->assertSessionHasErrors([
            'delivery_address' => 'Enter a delivery address.',
        ]);
});

test('pickup rejects a delivery address', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'delivery_address' => 'Address should not be retained',
            'payment_method' => 'cash',
        ])
        ->assertSessionHasErrors([
            'delivery_address' => 'A delivery address is not used for pickup orders.',
        ]);
});

test('delivery rejects payment methods that require paying at the store', function (string $paymentMethod) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'delivery',
            'delivery_address' => 'Sagay City, Negros Occidental',
            'payment_method' => $paymentMethod,
        ])
        ->assertSessionHasErrors([
            'payment_method' => 'Delivery orders must be paid through GCash or Maya.',
        ]);
})->with(['cash', 'card_at_store']);

test('gcash and maya require payment proof', function (string $paymentMethod) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => $paymentMethod,
        ])
        ->assertSessionHasErrors([
            'payment_proof' => 'Upload a screenshot or snapshot of your successful payment.',
        ]);
})->with(['gcash', 'maya']);

test('cash and card at store reject online payment proof', function (string $paymentMethod) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => $paymentMethod,
            'payment_proof' => UploadedFile::fake()->image('proof.png'),
        ])
        ->assertSessionHasErrors([
            'payment_proof' => 'Payment proof is not required for this payment method.',
        ]);
})->with(['cash', 'card_at_store']);

test('payment proof must be a supported image no larger than five megabytes', function (UploadedFile $proof, string $message) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => 'gcash',
            'payment_proof' => $proof,
        ])
        ->assertSessionHasErrors(['payment_proof' => $message]);
})->with([
    'document' => [
        fn () => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        'The payment proof must be an image.',
    ],
    'oversized image' => [
        fn () => UploadedFile::fake()->image('proof.png')->size(5121),
        'The payment proof may not be larger than 5 MB.',
    ],
]);

test('all eligible fulfillment and payment combinations place an order', function (
    string $fulfillmentMethod,
    string $paymentMethod,
    bool $requiresProof,
) {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $item = (new CartService)->add($customer, $product->id, 2);
    $payload = [
        'recipient_name' => 'Alex Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => $fulfillmentMethod,
        'payment_method' => $paymentMethod,
    ];

    if ($fulfillmentMethod === 'delivery') {
        $payload['delivery_address'] = 'Sagay City, Negros Occidental';
    }

    if ($requiresProof) {
        $payload['payment_proof'] = UploadedFile::fake()->image('proof.png');
    }

    $response = $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), $payload);
    $order = Order::query()->sole();

    $response
        ->assertRedirectToRoute('orders.show', $order)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash(
            'toast.message',
            'Order placed successfully.',
        );

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertModelMissing($item);
    expect($inventory->refresh()->quantity)->toBe(1)
        ->and($customer->refresh()->cart)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toHaveCount($requiresProof ? 1 : 0);
})->with([
    'pickup with cash' => ['pickup', 'cash', false],
    'pickup with card at store' => ['pickup', 'card_at_store', false],
    'pickup with gcash' => ['pickup', 'gcash', true],
    'pickup with maya' => ['pickup', 'maya', true],
    'delivery with gcash' => ['delivery', 'gcash', true],
    'delivery with maya' => ['delivery', 'maya', true],
]);

test('checkout rechecks current stock on submission', function () {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 2);
    $inventory->update(['quantity' => 1]);

    $this->actingAs($customer)
        ->from(route('checkout.index'))
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => 'gcash',
            'payment_proof' => UploadedFile::fake()->image('proof.png'),
        ])
        ->assertSessionHasErrors([
            'cart' => 'Review unavailable products or quantities in your cart before placing an order.',
        ]);

    expect($inventory->refresh()->quantity)->toBe(1);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    Storage::disk('local')->assertDirectoryEmpty('payment-proofs');
});

test('a checkout override is snapshotted without changing the profile default', function () {
    Storage::fake('local');
    $customer = User::factory()->customer()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'delivery',
            'delivery_address' => '99 Lopez Jaena Street, Sagay City',
            'payment_method' => 'gcash',
            'payment_proof' => UploadedFile::fake()->image('proof.png'),
        ])
        ->assertSessionHasNoErrors();

    $order = Order::query()->sole();

    expect($order->delivery_address)
        ->toBe('99 Lopez Jaena Street, Sagay City')
        ->and($customer->refresh()->default_delivery_address)
        ->toBe('12 Mabini Street, Sagay City');

    $customer->update([
        'default_delivery_address' => '45 Rizal Avenue, Escalante City',
    ]);

    expect($order->refresh()->delivery_address)
        ->toBe('99 Lopez Jaena Street, Sagay City');
});

test('pickup ignores the saved default delivery address', function () {
    $customer = User::factory()->customer()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    (new CartService)->add($customer, $product->id, 1);

    $this->actingAs($customer)
        ->post(route('orders.store'), [
            'recipient_name' => 'Alex Customer',
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => 'cash',
        ])
        ->assertSessionHasNoErrors();

    expect(Order::query()->sole()->delivery_address)->toBeNull()
        ->and($customer->refresh()->default_delivery_address)
        ->toBe('12 Mabini Street, Sagay City');
});
