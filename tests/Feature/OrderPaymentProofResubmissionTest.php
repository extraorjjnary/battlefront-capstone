<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests are redirected and administrators are forbidden from proof resubmission', function () {
    $order = Order::factory()->paidWithGCash()->withRejectedPaymentProof()->create();

    $this->post(route('orders.payment-proof.store', $order), [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
    ])->assertRedirectToRoute('login');

    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->post(route('orders.payment-proof.store', $order), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertForbidden();
});

test('an owner can replace rejected wallet proof and return payment to pending review', function (
    PaymentMethod $paymentMethod,
) {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/original.png', 'original-proof');
    $customer = User::factory()->customer()->create();
    $factory = Order::factory()->for($customer)->withRejectedPaymentProof();
    $order = ($paymentMethod === PaymentMethod::GCash
        ? $factory->paidWithGCash()
        : $factory->paidWithMaya())->create([
            'status' => OrderStatus::Processing,
            'payment_proof_path' => 'payment-proofs/original.png',
            'payment_rejection_reason' => PaymentRejectionReason::ImageUnclear,
            'payment_rejection_note' => 'The receipt details are blurred.',
        ]);

    $response = $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.payment-proof.store', $order), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ]);
    $order->refresh();

    $response
        ->assertRedirect(route('orders.show', $order))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash(
            'toast.message',
            'Replacement payment proof submitted for review.',
        );
    expect($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->status)->toBe(OrderStatus::Processing)
        ->and($order->payment_proof_path)->not->toBe('payment-proofs/original.png')
        ->and($order->payment_proof_path)->toStartWith('payment-proofs/')
        ->and($order->payment_rejection_reason)->toBeNull()
        ->and($order->payment_rejection_note)->toBeNull()
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
    Storage::disk('local')->assertExists($order->payment_proof_path);
    Storage::disk('local')->assertMissing('payment-proofs/original.png');
})->with([
    'GCash' => PaymentMethod::GCash,
    'Maya' => PaymentMethod::Maya,
]);

test('a customer cannot resubmit proof for another customers order', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/original.png', 'original-proof');
    $customer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->paidWithGCash()->withRejectedPaymentProof()->create([
        'payment_proof_path' => 'payment-proofs/original.png',
    ]);

    $this->actingAs($customer)
        ->post(route('orders.payment-proof.store', $foreignOrder), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertNotFound();

    expect($foreignOrder->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and(Storage::disk('local')->allFiles('payment-proofs'))->toBe([
            'payment-proofs/original.png',
        ]);
});

test('cash and card at store orders cannot accept replacement proof', function (PaymentMethod $paymentMethod) {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => $paymentMethod,
        'payment_status' => PaymentStatus::Rejected,
    ]);

    $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.payment-proof.store', $order), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertSessionHasErrors([
            'payment_proof' => 'Replacement proof is available only for GCash or Maya orders.',
        ]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and($order->payment_proof_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('payment-proofs');
})->with([
    'cash' => PaymentMethod::Cash,
    'card at store' => PaymentMethod::CardAtStore,
]);

test('wallet proof can be replaced only after rejection', function (PaymentStatus $paymentStatus) {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/original.png', 'original-proof');
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create([
        'payment_status' => $paymentStatus,
        'payment_proof_path' => 'payment-proofs/original.png',
    ]);

    $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.payment-proof.store', $order), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertSessionHasErrors([
            'payment_proof' => 'A replacement proof can be uploaded only after payment is rejected.',
        ]);

    expect($order->refresh()->payment_status)->toBe($paymentStatus)
        ->and($order->payment_proof_path)->toBe('payment-proofs/original.png')
        ->and(Storage::disk('local')->allFiles('payment-proofs'))->toBe([
            'payment-proofs/original.png',
        ]);
})->with([
    'pending payment' => PaymentStatus::Pending,
    'verified payment' => PaymentStatus::Verified,
]);

test('completed and cancelled orders cannot accept replacement proof', function (OrderStatus $orderStatus) {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/original.png', 'original-proof');
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithMaya()->withRejectedPaymentProof()->create([
        'status' => $orderStatus,
        'payment_proof_path' => 'payment-proofs/original.png',
    ]);

    $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.payment-proof.store', $order), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertSessionHasErrors([
            'payment_proof' => 'Completed or cancelled orders cannot accept a replacement proof.',
        ]);

    expect($order->refresh()->status)->toBe($orderStatus)
        ->and($order->payment_status)->toBe(PaymentStatus::Rejected)
        ->and($order->payment_proof_path)->toBe('payment-proofs/original.png');
})->with([
    'completed order' => OrderStatus::Completed,
    'cancelled order' => OrderStatus::Cancelled,
]);

test('replacement proof is required and must be a supported image no larger than five megabytes', function (
    ?UploadedFile $paymentProof,
    string $message,
) {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->withRejectedPaymentProof()->create();
    $payload = $paymentProof === null ? [] : ['payment_proof' => $paymentProof];

    $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.payment-proof.store', $order), $payload)
        ->assertSessionHasErrors(['payment_proof' => $message]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected);
})->with([
    'missing proof' => [null, 'Upload a replacement payment proof.'],
    'document' => [
        fn () => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        'The replacement proof must be an image.',
    ],
    'oversized image' => [
        fn () => UploadedFile::fake()->image('proof.png')->size(5121),
        'The replacement proof may not be larger than 5 MB.',
    ],
]);

test('unknown customer order paths return not found', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('orders.payment-proof.store', 999999), [
            'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertNotFound();
});
