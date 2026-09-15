<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResubmitPaymentProof
{
    /**
     * Replace rejected wallet evidence and return it to manual review.
     */
    public function execute(User $customer, Order $order, string $paymentProofPath): Order
    {
        [$updatedOrder, $previousPaymentProofPath] = DB::transaction(
            function () use ($customer, $order, $paymentProofPath): array {
                $lockedOrder = Order::query()
                    ->whereKey($order->getKey())
                    ->whereBelongsTo($customer)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedOrder->payment_method->requiresPaymentProof()) {
                    throw ValidationException::withMessages([
                        'payment_proof' => 'Replacement proof is available only for GCash or Maya orders.',
                    ]);
                }

                if ($lockedOrder->payment_status !== PaymentStatus::Rejected) {
                    throw ValidationException::withMessages([
                        'payment_proof' => 'A replacement proof can be uploaded only after payment is rejected.',
                    ]);
                }

                if (in_array($lockedOrder->status, [OrderStatus::Completed, OrderStatus::Cancelled], strict: true)) {
                    throw ValidationException::withMessages([
                        'payment_proof' => 'Completed or cancelled orders cannot accept a replacement proof.',
                    ]);
                }

                $previousPaymentProofPath = $lockedOrder->payment_proof_path;

                $lockedOrder->update([
                    'payment_proof_path' => $paymentProofPath,
                    'payment_status' => PaymentStatus::Pending,
                    'payment_rejection_reason' => null,
                    'payment_rejection_note' => null,
                ]);

                return [$lockedOrder->refresh(), $previousPaymentProofPath];
            },
            attempts: 3,
        );

        if (
            is_string($previousPaymentProofPath)
            && $previousPaymentProofPath !== $paymentProofPath
            && Str::startsWith($previousPaymentProofPath, 'payment-proofs/')
        ) {
            Storage::disk('local')->delete($previousPaymentProofPath);
        }

        return $updatedOrder;
    }
}
