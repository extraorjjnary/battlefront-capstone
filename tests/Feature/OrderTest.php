<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the order schema follows the approved ERD and checkout amendments', function () {
    expect(Schema::getColumnListing('orders'))->toEqualCanonicalizing([
        'id',
        'user_id',
        'recipient_name',
        'contact_number',
        'fulfillment_method',
        'delivery_address',
        'total_amount',
        'status',
        'payment_status',
        'payment_method',
        'payment_proof_path',
        'payment_rejection_reason',
        'payment_rejection_note',
        'created_at',
    ]);
});

test('an order persists approved snapshots defaults and casts', function () {
    $order = Order::factory()->delivery()->create([
        'recipient_name' => 'Juan Dela Cruz',
        'contact_number' => '0912 345 6789',
        'delivery_address' => 'Sagay City, Negros Occidental',
        'total_amount' => '38999.90',
        'payment_method' => PaymentMethod::CardAtStore,
    ]);

    $this->assertModelExists($order);
    expect($order->recipient_name)->toBe('Juan Dela Cruz')
        ->and($order->contact_number)->toBe('0912 345 6789')
        ->and($order->fulfillment_method)->toBe(FulfillmentMethod::Delivery)
        ->and($order->delivery_address)->toBe('Sagay City, Negros Occidental')
        ->and($order->total_amount)->toBe('38999.90')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_method)->toBe(PaymentMethod::CardAtStore)
        ->and($order->payment_proof_path)->toBeNull()
        ->and($order->payment_rejection_reason)->toBeNull()
        ->and($order->payment_rejection_note)->toBeNull()
        ->and($order->created_at)->not->toBeNull();
});

test('wallet rejection feedback persists with an enum cast', function () {
    $order = Order::factory()->paidWithGCash()->withRejectedPaymentProof()->create([
        'payment_rejection_reason' => PaymentRejectionReason::ImageUnclear,
        'payment_rejection_note' => 'The reference number is cropped.',
    ]);

    expect($order->payment_rejection_reason)->toBe(PaymentRejectionReason::ImageUnclear)
        ->and($order->payment_rejection_note)->toBe('The reference number is cropped.');
});

test('a customer owns orders through reciprocal relationships', function () {
    $customer = User::factory()->customer()->create();
    $firstOrder = Order::factory()->for($customer)->create();
    $secondOrder = Order::factory()->for($customer)->create();

    expect($firstOrder->user->is($customer))->toBeTrue()
        ->and($secondOrder->user->is($customer))->toBeTrue()
        ->and($customer->orders->modelKeys())->toBe([$firstOrder->id, $secondOrder->id]);
});

test('an administrator cannot own an order', function () {
    $administrator = User::factory()->administrator()->create();

    expect(fn () => Order::factory()->for($administrator)->create())
        ->toThrow(DomainException::class, 'Orders may only belong to customers.');
    expect(Order::query()->count())->toBe(0);
});

test('pickup orders always keep the delivery address null', function () {
    $order = Order::factory()->create([
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'delivery_address' => 'This address must not be retained.',
    ]);

    expect($order->delivery_address)->toBeNull()
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update([
            'delivery_address' => 'Bypass the model.',
        ]))->toThrow(QueryException::class);
});

test('delivery addresses remain nullable at the persistence layer', function () {
    $order = Order::factory()->delivery()->create(['delivery_address' => null]);

    $this->assertModelExists($order);
    expect($order->fulfillment_method)->toBe(FulfillmentMethod::Delivery)
        ->and($order->delivery_address)->toBeNull();
});

test('approved manual payment methods persist without requiring proof', function (PaymentMethod $paymentMethod) {
    $order = Order::factory()->create([
        'payment_method' => $paymentMethod,
        'payment_proof_path' => null,
    ]);

    expect($order->payment_method)->toBe($paymentMethod)
        ->and($order->payment_proof_path)->toBeNull();
})->with([
    'cash' => PaymentMethod::Cash,
    'card at store' => PaymentMethod::CardAtStore,
    'GCash' => PaymentMethod::GCash,
    'Maya' => PaymentMethod::Maya,
]);

test('payment proof references are nullable private paths hidden from serialization', function () {
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => 'payment-proofs/gcash-proof.jpg',
    ]);

    expect($order->payment_proof_path)->toBe('payment-proofs/gcash-proof.jpg')
        ->and($order->toArray())->not->toHaveKey('payment_proof_path')
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});

test('invalid order values are rejected by persistence constraints', function (string $column, mixed $value) {
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->where('id', $order->id)->update([$column => $value]))
        ->toThrow(QueryException::class);
})->with([
    'negative total' => ['total_amount', '-0.01'],
    'unknown fulfillment method' => ['fulfillment_method', 'shipping'],
    'unknown order status' => ['status', 'refunded'],
    'unknown payment status' => ['payment_status', 'paid'],
    'unknown payment method' => ['payment_method', 'credit_card'],
]);

test('required order fields cannot be omitted', function (string $missingField) {
    $attributes = [
        'user_id' => User::factory()->customer()->create()->id,
        'recipient_name' => 'Juan Dela Cruz',
        'contact_number' => '0912 345 6789',
        'fulfillment_method' => FulfillmentMethod::Pickup->value,
        'delivery_address' => null,
        'total_amount' => '100.00',
        'payment_method' => PaymentMethod::Cash->value,
        'payment_proof_path' => null,
    ];
    unset($attributes[$missingField]);

    expect(fn () => DB::table('orders')->insert($attributes))
        ->toThrow(QueryException::class);
})->with([
    'customer' => 'user_id',
    'recipient name' => 'recipient_name',
    'contact number' => 'contact_number',
    'fulfillment method' => 'fulfillment_method',
    'total amount' => 'total_amount',
    'payment method' => 'payment_method',
]);

test('customers with historical orders cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->user->delete())->toThrow(QueryException::class);
    $this->assertModelExists($order);
});
