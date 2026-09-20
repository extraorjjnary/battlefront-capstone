<?php

namespace App\Actions\Order;

use App\Enums\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class StorePaymentProof
{
    /**
     * Store required e-wallet evidence on the private disk.
     */
    public function execute(?UploadedFile $paymentProof, PaymentMethod $paymentMethod): ?string
    {
        if (! $paymentMethod->requiresPaymentProof()) {
            return null;
        }

        $path = $paymentProof?->store('payment-proofs', 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'The payment proof could not be stored. Please try again.',
            ]);
        }

        return $path;
    }
}
