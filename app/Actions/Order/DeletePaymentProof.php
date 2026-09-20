<?php

namespace App\Actions\Order;

use Illuminate\Support\Facades\Storage;

class DeletePaymentProof
{
    /**
     * Remove evidence that no committed order references.
     */
    public function execute(?string $paymentProofPath): void
    {
        if ($paymentProofPath !== null) {
            Storage::disk('local')->delete($paymentProofPath);
        }
    }
}
