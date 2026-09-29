<?php

use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->customer = User::factory()->customer()->create();
    $this->withToken($this->customer->createToken('Phone')->plainTextToken);
});

test('mobile customers replace rejected wallet proof without changing stock or order status', function (string $method) {
    Storage::disk('local')->put('payment-proofs/old.png', 'old');
    $order = Order::factory()->for($this->customer)->withRejectedPaymentProof()->create([
        'payment_method' => $method, 'payment_proof_path' => 'payment-proofs/old.png',
        'payment_rejection_note' => 'Unclear receipt', 'status' => OrderStatus::Processing,
    ]);
    $stock = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->create(['product_id' => $stock->product_id, 'quantity' => 2]);

    $response = $this->post('/api/v1/orders/'.$order->id.'/payment-proof', [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
        'status' => 'completed', 'payment_status' => 'verified', 'total_amount' => '0.01',
    ])->assertOk()
        ->assertJsonPath('data.payment.status.value', 'pending')
        ->assertJsonPath('data.payment.rejection', null)
        ->assertJsonPath('data.payment.can_resubmit_proof', false)
        ->assertJsonPath('data.payment.proof_submitted', true)
        ->assertJsonPath('data.status.value', 'processing');

    $originalTotal = $order->total_amount;
    $order->refresh();
    Storage::disk('local')->assertExists($order->payment_proof_path);
    Storage::disk('local')->assertMissing('payment-proofs/old.png');
    expect($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_rejection_reason)->toBeNull()
        ->and($order->payment_rejection_note)->toBeNull()
        ->and($order->total_amount)->toBe($originalTotal)
        ->and($stock->refresh()->quantity)->toBe(3)
        ->and($response->getContent())->not->toContain('payment-proofs/');
})->with(['gcash', 'maya']);

test('mobile proof replacement conceals foreign and missing orders without storing files', function (bool $foreign) {
    $order = Order::factory()->paidWithGCash()->withRejectedPaymentProof()->create();
    $id = $foreign ? $order->id : $order->id + 100;

    $this->post('/api/v1/orders/'.$id.'/payment-proof', [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
    ])->assertNotFound()->assertJsonStructure(['message']);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected);
})->with([true, false]);

test('mobile proof replacement applies existing eligibility and cleans failed uploads', function (array $changes, string $message) {
    Storage::disk('local')->put('payment-proofs/old.png', 'old');
    $order = Order::factory()->for($this->customer)->paidWithGCash()->withRejectedPaymentProof()->create(
        array_replace(['payment_proof_path' => 'payment-proofs/old.png'], $changes),
    );
    $original = $order->refresh()->getAttributes();

    $this->post('/api/v1/orders/'.$order->id.'/payment-proof', [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
    ])->assertUnprocessable()->assertExactJson([
        'message' => $message, 'errors' => ['payment_proof' => [$message]],
    ]);

    expect($order->refresh()->getAttributes())->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toBe(['payment-proofs/old.png']);
})->with([
    [['payment_method' => 'cash'], 'Replacement proof is available only for GCash or Maya orders.'],
    [['payment_method' => 'card_at_store'], 'Replacement proof is available only for GCash or Maya orders.'],
    [['payment_status' => 'pending'], 'A replacement proof can be uploaded only after payment is rejected.'],
    [['payment_status' => 'verified'], 'A replacement proof can be uploaded only after payment is rejected.'],
    [['status' => 'completed'], 'Completed or cancelled orders cannot accept a replacement proof.'],
    [['status' => 'cancelled'], 'Completed or cancelled orders cannot accept a replacement proof.'],
]);

test('mobile replacement proof validates image uploads', function (Closure $upload) {
    $order = Order::factory()->for($this->customer)->paidWithGCash()->withRejectedPaymentProof()->create();

    $this->post('/api/v1/orders/'.$order->id.'/payment-proof', ['payment_proof' => $upload()])
        ->assertUnprocessable()->assertJsonValidationErrors('payment_proof');
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    fn () => null,
    fn () => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
    fn () => UploadedFile::fake()->image('proof.png')->size(5121),
]);

test('replacement upload is retained if failure happens after its reference is committed', function () {
    $order = Order::factory()->for($this->customer)->paidWithGCash()->withRejectedPaymentProof()->create();
    $this->mock(ResubmitPaymentProof::class)->shouldReceive('execute')->once()
        ->andReturnUsing(function (User $customer, Order $persistedOrder, string $path): never {
            $persistedOrder->update(['payment_proof_path' => $path, 'payment_status' => PaymentStatus::Pending]);
            throw new RuntimeException('Post-commit cleanup failure.');
        });
    config(['app.debug' => false]);

    $this->post('/api/v1/orders/'.$order->id.'/payment-proof', [
        'payment_proof' => UploadedFile::fake()->image('replacement.png'),
    ])->assertInternalServerError();

    $order->refresh();
    expect($order->payment_status)->toBe(PaymentStatus::Pending);
    Storage::disk('local')->assertExists($order->payment_proof_path);
});
