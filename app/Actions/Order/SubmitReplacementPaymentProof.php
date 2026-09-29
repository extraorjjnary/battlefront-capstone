<?php

namespace App\Actions\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitReplacementPaymentProof
{
    public function __construct(private readonly ResubmitPaymentProof $resubmitPaymentProof) {}

    public function execute(User $customer, int $order, ?UploadedFile $paymentProof): Order
    {
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();
        $paymentProofPath = $paymentProof?->store('payment-proofs', 'local');

        if (! is_string($paymentProofPath)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'The replacement proof could not be stored. Please try again.',
            ]);
        }

        try {
            return $this->resubmitPaymentProof->execute($customer, $persistedOrder, $paymentProofPath);
        } catch (Throwable $exception) {
            if (! $customer->orders()->whereKey($order)->where('payment_proof_path', $paymentProofPath)->exists()) {
                Storage::disk('local')->delete($paymentProofPath);
            }

            throw $exception;
        }
    }
}
