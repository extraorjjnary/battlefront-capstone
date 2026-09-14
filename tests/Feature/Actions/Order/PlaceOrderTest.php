<?php

use App\Actions\Cart\ManageCart;
use App\Actions\Order\OrderPlacementException;
use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * @return array{
 *     recipient_name: string,
 *     contact_number: string,
 *     fulfillment_method: FulfillmentMethod,
 *     delivery_address: string|null,
 *     payment_method: PaymentMethod,
 *     payment_proof_path: string|null
 * }
 */
function validOrderPlacementData(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Alex Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash,
        'payment_proof_path' => null,
    ], $overrides);
}

test('places an order with current price snapshots and consumes stock and cart', function () {
    $customer = User::factory()->customer()->create();
    $regularProduct = Product::factory()->create(['price' => '12500.00']);
    $discountedProduct = Product::factory()->create([
        'price' => '5000.00',
        'discount_price' => '4500.00',
    ]);
    $regularInventory = Inventory::factory()->for($regularProduct)->create(['quantity' => 5]);
    $discountedInventory = Inventory::factory()->for($discountedProduct)->create(['quantity' => 4]);
    $manageCart = new ManageCart;
    $manageCart->add($customer, $regularProduct->id, 2);
    $manageCart->add($customer, $discountedProduct->id, 3);

    $order = app(PlaceOrder::class)->execute($customer, validOrderPlacementData([
        'fulfillment_method' => FulfillmentMethod::Delivery,
        'delivery_address' => 'Sagay City, Negros Occidental',
        'payment_method' => PaymentMethod::GCash,
        'payment_proof_path' => 'payment-proofs/proof.png',
    ]));

    expect($order->user->is($customer))->toBeTrue()
        ->and($order->recipient_name)->toBe('Alex Customer')
        ->and($order->contact_number)->toBe('09171234567')
        ->and($order->fulfillment_method)->toBe(FulfillmentMethod::Delivery)
        ->and($order->delivery_address)->toBe('Sagay City, Negros Occidental')
        ->and($order->total_amount)->toBe('38500.00')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_method)->toBe(PaymentMethod::GCash)
        ->and($order->payment_proof_path)->toBe('payment-proofs/proof.png')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->pluck('quantity', 'product_id')->all())->toBe([
            $regularProduct->id => 2,
            $discountedProduct->id => 3,
        ])
        ->and($order->items->pluck('price_at_time', 'product_id')->all())->toBe([
            $regularProduct->id => '12500.00',
            $discountedProduct->id => '4500.00',
        ])
        ->and($regularInventory->refresh()->quantity)->toBe(3)
        ->and($discountedInventory->refresh()->quantity)->toBe(1)
        ->and($customer->refresh()->cart)->toBeNull();
});

test('rolls back order stock and cart changes when an order item fails', function () {
    $customer = User::factory()->customer()->create();
    $firstProduct = Product::factory()->create(['price' => '100.00']);
    $secondProduct = Product::factory()->create(['price' => '200.00']);
    $firstInventory = Inventory::factory()->for($firstProduct)->create(['quantity' => 3]);
    $secondInventory = Inventory::factory()->for($secondProduct)->create(['quantity' => 3]);
    $manageCart = new ManageCart;
    $firstItem = $manageCart->add($customer, $firstProduct->id, 1);
    $secondItem = $manageCart->add($customer, $secondProduct->id, 1);
    Event::listen(
        'eloquent.creating: '.OrderItem::class,
        function (OrderItem $item) use ($secondProduct): void {
            if ($item->product_id === $secondProduct->id) {
                throw new RuntimeException('Forced order item failure.');
            }
        },
    );

    try {
        expect(fn () => app(PlaceOrder::class)->execute($customer, validOrderPlacementData()))
            ->toThrow(RuntimeException::class, 'Forced order item failure.');
    } finally {
        Event::forget('eloquent.creating: '.OrderItem::class);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertModelExists($firstItem);
    $this->assertModelExists($secondItem);
    expect($firstInventory->refresh()->quantity)->toBe(3)
        ->and($secondInventory->refresh()->quantity)->toBe(3)
        ->and($customer->refresh()->cart)->not->toBeNull();
});

test('prevents competing carts from overselling current stock', function () {
    $firstCustomer = User::factory()->customer()->create();
    $secondCustomer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $manageCart = new ManageCart;
    $manageCart->add($firstCustomer, $product->id, 2);
    $secondItem = $manageCart->add($secondCustomer, $product->id, 2);

    app(PlaceOrder::class)->execute($firstCustomer, validOrderPlacementData());

    expect(fn () => app(PlaceOrder::class)->execute($secondCustomer, validOrderPlacementData()))
        ->toThrow(
            OrderPlacementException::class,
            'Review unavailable products or quantities in your cart before placing an order.',
        );
    expect($inventory->refresh()->quantity)->toBe(1);
    $this->assertDatabaseCount('orders', 1);
    $this->assertModelExists($secondItem);
    expect($secondCustomer->refresh()->cart)->not->toBeNull();
});

test('a repeated placement cannot deduct initial stock twice', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5]);
    (new ManageCart)->add($customer, $product->id, 2);
    $placeOrder = app(PlaceOrder::class);

    $placeOrder->execute($customer, validOrderPlacementData());

    expect(fn () => $placeOrder->execute($customer, validOrderPlacementData()))
        ->toThrow(
            OrderPlacementException::class,
            'Add at least one available product before placing an order.',
        );
    expect($inventory->refresh()->quantity)->toBe(3);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
});

test('later order and payment status changes do not deduct stock again', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5]);
    (new ManageCart)->add($customer, $product->id, 2);
    $order = app(PlaceOrder::class)->execute($customer, validOrderPlacementData());

    $order->update([
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);

    expect($inventory->refresh()->quantity)->toBe(3)
        ->and($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->payment_status)->toBe(PaymentStatus::Verified);
});
