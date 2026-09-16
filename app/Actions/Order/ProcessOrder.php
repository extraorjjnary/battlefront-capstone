<?php

namespace App\Actions\Order;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProcessOrder
{
    public function __construct(
        private readonly AdjustInventoryStock $adjustInventoryStock,
    ) {}

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
                in_array($status, [OrderStatus::Processing, OrderStatus::Completed], strict: true)
                && $lockedOrder->payment_status !== PaymentStatus::Verified
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Verify payment before processing or completing this order.',
                ]);
            }

            if ($status === OrderStatus::Cancelled) {
                $this->restoreInventory($lockedOrder);
            }

            $lockedOrder->update(['status' => $status]);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    /**
     * Restore the quantities purchased by a cancelled order.
     */
    private function restoreInventory(Order $order): void
    {
        $orderItems = $order->items()
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $inventories = Inventory::query()
            ->whereIn('product_id', $orderItems->pluck('product_id')->unique())
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        foreach ($orderItems as $orderItem) {
            /** @var Inventory|null $inventory */
            $inventory = $inventories->get($orderItem->product_id);

            if ($inventory === null) {
                throw new LogicException('An order item has no inventory record to restore.');
            }

            $this->adjustInventoryStock->increase($inventory, $orderItem->quantity);
        }
    }

    /**
     * Record an administrator's final manual payment decision.
     */
    public function updatePaymentStatus(
        Order $order,
        PaymentStatus $paymentStatus,
        ?PaymentRejectionReason $rejectionReason = null,
        ?string $rejectionNote = null,
    ): Order {
        return DB::transaction(function () use ($order, $paymentStatus, $rejectionReason, $rejectionNote): Order {
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

            $rejectingWalletPayment = $paymentStatus === PaymentStatus::Rejected
                && $lockedOrder->payment_method->requiresPaymentProof();
            $normalizedRejectionNote = filled($rejectionNote)
                ? trim($rejectionNote)
                : null;

            if ($rejectingWalletPayment && $rejectionReason === null) {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'Select why the payment proof was rejected.',
                ]);
            }

            if (
                $rejectingWalletPayment
                && $rejectionReason === PaymentRejectionReason::Other
                && $normalizedRejectionNote === null
            ) {
                throw ValidationException::withMessages([
                    'rejection_note' => 'Explain why the payment proof was rejected when selecting Other.',
                ]);
            }

            $lockedOrder->update([
                'payment_status' => $paymentStatus,
                'payment_rejection_reason' => $rejectingWalletPayment ? $rejectionReason : null,
                'payment_rejection_note' => $rejectingWalletPayment ? $normalizedRejectionNote : null,
            ]);

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
