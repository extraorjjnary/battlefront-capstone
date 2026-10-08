<?php

namespace App\Services\Order;

use App\Actions\Inventory\RestoreOrderInventory;
use App\Actions\Order\RecordCompletedOrderSale;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderProcessingService
{
    public function __construct(
        private readonly RestoreOrderInventory $restoreOrderInventory,
        private readonly RecordCompletedOrderSale $recordCompletedOrderSale,
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

            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            if ($status === OrderStatus::Completed && $shipment !== null) {
                throw ValidationException::withMessages(['status' => 'Complete delivery through the shipment Delivered milestone.']);
            }
            $this->transitionLockedOrder($lockedOrder, $status, $shipment);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    /** @return list<OrderStatus> */
    public function allowedOrderTransitions(Order $order): array
    {
        return array_values(array_filter($order->status->allowedTransitions(), function (OrderStatus $status) use ($order): bool {
            if ($status === OrderStatus::Completed && $order->shipment !== null) {
                return false;
            }

            return $status === OrderStatus::Cancelled || $order->payment_status === PaymentStatus::Verified;
        }));
    }

    public function nextShipmentStatus(Order $order): ?ShipmentStatus
    {
        if ($order->fulfillment_method !== FulfillmentMethod::Delivery
            || $order->status !== OrderStatus::Processing
            || $order->payment_status !== PaymentStatus::Verified) {
            return null;
        }

        return $order->shipment?->status->next();
    }

    public function canUpdateShipmentReference(Order $order): bool
    {
        $shipment = $order->shipment;

        return $order->fulfillment_method === FulfillmentMethod::Delivery
            && $shipment !== null
            && $order->payment_status === PaymentStatus::Verified
            && in_array($shipment->status, [ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], strict: true)
            && ($order->status === OrderStatus::Processing
                || ($order->status === OrderStatus::Completed && $shipment->status === ShipmentStatus::Delivered));
    }

    public function updateShipmentStatus(Order $order, ShipmentStatus $status, ?string $trackingReference = null): Order
    {
        return DB::transaction(function () use ($order, $status, $trackingReference): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if ($this->nextShipmentStatus($lockedOrder) !== $status) {
                throw ValidationException::withMessages([
                    'status' => 'Shipment progression requires verified payment, a processing delivery order, and the next milestone only.',
                ]);
            }

            if ($shipment === null) {
                throw ValidationException::withMessages(['status' => 'This order has no shipment.']);
            }

            $changes = ['status' => $status, $status->timestampColumn() => now()];
            if ($status === ShipmentStatus::Preparing) {
                $anchor = CarbonImmutable::now(config('app.timezone'))->startOfDay();
                $changes += [
                    'eta_anchor_date' => $anchor->toDateString(),
                    'eta_timezone' => $anchor->timezoneName,
                    'estimated_delivery_start' => $anchor->addDays($shipment->eta_min_days)->toDateString(),
                    'estimated_delivery_end' => $anchor->addDays($shipment->eta_max_days)->toDateString(),
                ];
            }

            if ($trackingReference !== null) {
                if ($status !== ShipmentStatus::HandedToLbc) {
                    throw ValidationException::withMessages(['tracking_reference' => 'A reference may first be entered when handing the shipment to LBC.']);
                }
                $changes['tracking_reference'] = $this->normalizeTrackingReference($trackingReference);
            }

            $shipment->update($changes);

            if ($status === ShipmentStatus::Delivered) {
                $this->transitionLockedOrder($lockedOrder, OrderStatus::Completed, $shipment);
            }

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function updateShipmentReference(Order $order, ?string $trackingReference): Order
    {
        return DB::transaction(function () use ($order, $trackingReference): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $this->canUpdateShipmentReference($lockedOrder) || $shipment === null) {
                throw ValidationException::withMessages(['tracking_reference' => 'References can only be maintained for eligible shipments handed to LBC.']);
            }

            $shipment->update(['tracking_reference' => $this->normalizeTrackingReference($trackingReference)]);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    private function normalizeTrackingReference(?string $reference): ?string
    {
        $normalized = filled($reference) ? trim($reference) : null;
        if ($normalized !== null && mb_strlen($normalized) > 255) {
            throw ValidationException::withMessages(['tracking_reference' => 'The tracking reference must not exceed 255 characters.']);
        }

        return $normalized;
    }

    private function transitionLockedOrder(Order $order, OrderStatus $status, ?Shipment $shipment): void
    {
        if (! $order->status->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => 'This order cannot move to the selected status.']);
        }

        if (in_array($status, [OrderStatus::Processing, OrderStatus::Completed], strict: true)
            && $order->payment_status !== PaymentStatus::Verified) {
            throw ValidationException::withMessages(['status' => 'Verify payment before processing or completing this order.']);
        }

        if ($status === OrderStatus::Completed && $shipment !== null && $shipment->status !== ShipmentStatus::Delivered) {
            throw ValidationException::withMessages(['status' => 'Complete delivery through the shipment Delivered milestone.']);
        }

        if ($status === OrderStatus::Cancelled) {
            if ($shipment?->status === ShipmentStatus::Delivered) {
                throw ValidationException::withMessages(['status' => 'A delivered shipment cannot be cancelled.']);
            }
            $this->restoreOrderInventory->execute($order);
            $shipment?->update(['status' => ShipmentStatus::Cancelled, 'cancelled_at' => now()]);
        }

        $order->update(['status' => $status]);
        if ($status === OrderStatus::Completed) {
            $this->recordCompletedOrderSale->execute($order);
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
