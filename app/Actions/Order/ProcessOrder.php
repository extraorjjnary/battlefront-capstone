<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessOrder
{
    /**
     * Move an order through an approved administrator transition.
     */
    public function updateStatus(Order $order, OrderStatus $status): Order
    {
        return DB::transaction(function () use ($order, $status): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOrder->status->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => 'This order cannot move to the selected status.',
                ]);
            }

            if (
                $status === OrderStatus::Completed
                && $lockedOrder->payment_status !== PaymentStatus::Verified
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Verify payment before completing this order.',
                ]);
            }

            $lockedOrder->update(['status' => $status]);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    /**
     * Record an administrator's final manual payment decision.
     */
    public function updatePaymentStatus(Order $order, PaymentStatus $paymentStatus): Order
    {
        return DB::transaction(function () use ($order, $paymentStatus): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->payment_status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment_status' => 'This payment already has a final decision.',
                ]);
            }

            if ($paymentStatus === PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Select verified or rejected for the payment decision.',
                ]);
            }

            if (
                $lockedOrder->status === OrderStatus::Completed
                && $paymentStatus === PaymentStatus::Rejected
            ) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Payment cannot be rejected after the order is completed.',
                ]);
            }

            if (
                $paymentStatus === PaymentStatus::Verified
                && $lockedOrder->payment_method->requiresPaymentProof()
                && ! $this->hasAccessiblePaymentProof($lockedOrder)
            ) {
                throw ValidationException::withMessages([
                    'payment_status' => 'The submitted payment proof is unavailable and cannot be verified.',
                ]);
            }

            $lockedOrder->update(['payment_status' => $paymentStatus]);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    /**
     * Determine whether an order points to evidence in the private proof directory.
     */
    private function hasAccessiblePaymentProof(Order $order): bool
    {
        return is_string($order->payment_proof_path)
            && Str::startsWith($order->payment_proof_path, 'payment-proofs/')
            && Storage::disk('local')->exists($order->payment_proof_path);
    }
}
