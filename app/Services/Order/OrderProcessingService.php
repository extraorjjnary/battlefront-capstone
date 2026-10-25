<?php

namespace App\Services\Order;

use App\Actions\Inventory\RestoreOrderInventory;
use App\Actions\Order\RecordCompletedOrderSale;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentArrangement;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\PayMongo\CloseHostedCheckout;
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
        private readonly OrderNotificationPublisher $notifications,
        private readonly CloseHostedCheckout $closeHostedCheckout,
    ) {}

    public function canCustomerCancel(Order $order): bool
    {
        $gatewayFlowCanClose = $order->payment_origin !== 'paymongo'
            || PayMongoFlow::query()->where('order_id', $order->id)
                ->whereIn('status', ['open', 'closing', 'closed', 'reconciliation_required'])
                ->exists();

        return $gatewayFlowCanClose
            && $order->status === OrderStatus::Pending
            && $order->payment_status === PaymentStatus::Pending
            && (! $order->payment_method->requiresPaymentProof() || $order->payment_proof_path === null)
            && ($order->shipment === null || $order->shipment->status === ShipmentStatus::AwaitingPreparation);
    }

    public function cancelForCustomer(User $customer, int $orderId): Order
    {
        $order = $customer->orders()->findOrFail($orderId);

        if ($order->payment_origin === 'paymongo' && ! $this->closeHostedCheckout->execute($order->id)) {
            throw ValidationException::withMessages([
                'status' => $this->payMongoCancellationBlockedMessage($order),
            ]);
        }

        return DB::transaction(function () use ($customer, $orderId): Order {
            $order = $customer->orders()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $shipment = $order->shipment()->lockForUpdate()->first();
            $order->setRelation('shipment', $shipment);

            if (! $this->canCustomerCancel($order)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending orders with pending payment, no submitted wallet proof, and no shipment preparation can be cancelled. Refresh your order to review its current status.',
                ]);
            }

            $this->transitionLockedOrder($order, OrderStatus::Cancelled, $shipment, cancellationInitiator: UserRole::Customer);

            return $order->refresh();
        }, attempts: 3);
    }

    public function shipmentCancellationBlockedReason(?Shipment $shipment): ?string
    {
        if ($shipment === null || in_array($shipment->status, [ShipmentStatus::AwaitingPreparation, ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch], strict: true)) {
            return null;
        }

        return $shipment->status === ShipmentStatus::Cancelled
            ? 'This shipment is already cancelled.'
            : 'This shipment has been handed to LBC. Normal order cancellation is no longer available.';
    }

    /**
     * Move an order through an approved administrator transition.
     */
    public function updateStatus(Order $order, OrderStatus $status, ?User $actor = null, bool $productsHandedOver = false): Order
    {
        if ($status === OrderStatus::Cancelled && $order->payment_origin === 'paymongo'
            && ! $this->closeHostedCheckout->execute($order->id)) {
            throw ValidationException::withMessages([
                'status' => $this->payMongoCancellationBlockedMessage($order),
            ]);
        }

        return DB::transaction(function () use ($order, $status, $actor, $productsHandedOver): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);
            if ($status === OrderStatus::Cancelled && $lockedOrder->payment_origin === 'paymongo'
                && ($lockedOrder->payment_status !== PaymentStatus::Pending
                    || PayMongoFlow::query()->where('order_id', $lockedOrder->id)->where('status', 'closed')->doesntExist())) {
                throw ValidationException::withMessages(['status' => 'Online payment must be confirmed closed and unpaid before this order can be cancelled.']);
            }
            if ($status === OrderStatus::Completed && $lockedOrder->payment_method->isPayAtPickup()) {
                throw ValidationException::withMessages(['status' => 'Use Confirm Payment & Complete Pickup after receiving payment and handing over the products.']);
            }
            if ($status === OrderStatus::Completed && ($shipment !== null || $lockedOrder->payment_method === PaymentMethod::Cod)) {
                throw ValidationException::withMessages(['status' => $lockedOrder->payment_method === PaymentMethod::Cod
                    ? 'Complete COD delivery through Confirm COD Collection after the Delivered milestone.'
                    : 'Complete delivery through the shipment Delivered milestone.']);
            }
            $this->transitionLockedOrder($lockedOrder, $status, $shipment, actor: $actor, productsHandedOver: $productsHandedOver);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    /** @return list<OrderStatus> */
    public function allowedOrderTransitions(Order $order): array
    {
        return array_values(array_filter($order->status->allowedTransitions(), function (OrderStatus $status) use ($order): bool {
            if ($status === OrderStatus::Completed && ($order->shipment !== null || $order->payment_method === PaymentMethod::Cod || $order->payment_method->isPayAtPickup())) {
                return false;
            }

            if ($status === OrderStatus::Cancelled) {
                return ($order->payment_origin !== 'paymongo'
                    || PayMongoFlow::query()->where('order_id', $order->id)
                        ->whereIn('status', ['open', 'closing', 'closed', 'awaiting_payment_method', 'failed', 'reconciliation_required'])
                        ->exists())
                    && $this->shipmentCancellationBlockedReason($order->shipment) === null;
            }

            return $status === OrderStatus::Processing
                ? $this->paymentAllowsFulfillment($order)
                : $order->payment_status === PaymentStatus::Verified;
        }));
    }

    public function nextShipmentStatus(Order $order): ?ShipmentStatus
    {
        if ($order->fulfillment_method !== FulfillmentMethod::Delivery
            || $order->status !== OrderStatus::Processing
            || ! $this->paymentAllowsFulfillment($order)) {
            return null;
        }

        return $order->shipment?->status->next();
    }

    public function canCreateManualShipment(Order $order): bool
    {
        return $order->fulfillment_method === FulfillmentMethod::Delivery
            && ($order->delivery_quote_metadata['pricing_status'] ?? null) === 'not_configured'
            && $order->delivery_fee === '0.00'
            && $order->product_subtotal !== null
            && bccomp($order->total_amount, $order->product_subtotal, 2) === 0
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], strict: true)
            && $order->payment_status !== PaymentStatus::Rejected
            && $order->shipment === null;
    }

    public function canSimulateLocalLbcBooking(Order $order): bool
    {
        return $this->localLbcSimulationEnabled()
            && $order->fulfillment_method === FulfillmentMethod::Delivery
            && $order->status === OrderStatus::Processing
            && $this->paymentAllowsFulfillment($order)
            && $order->shipment !== null
            && $order->shipment->carrier === 'lbc'
            && $order->shipment->arrangement_type === ShipmentArrangement::ConfiguredRate
            && ($order->delivery_quote_metadata['pricing_status'] ?? null) === 'configured_estimate'
            && bccomp($order->delivery_fee, '0.00', 2) > 0
            && $order->shipment->status === ShipmentStatus::AwaitingPreparation
            && $order->shipment->booking_source === null
            && $order->shipment->booking_reference === null;
    }

    public function simulateLocalLbcBooking(Order $order, ?User $actor = null): Shipment
    {
        return DB::transaction(function () use ($order, $actor): Shipment {
            if (! $this->localLbcSimulationEnabled()) {
                throw ValidationException::withMessages([
                    'booking' => 'The local LBC simulation is disabled outside explicitly enabled local/test environments.',
                ]);
            }

            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if ($shipment?->booking_source === 'battlefront_simulation' && filled($shipment->booking_reference)) {
                return $shipment;
            }

            if (! $this->canSimulateLocalLbcBooking($lockedOrder)) {
                throw ValidationException::withMessages([
                    'booking' => 'The local simulation is available only for enabled local/test, configured LBC delivery shipments awaiting preparation.',
                ]);
            }

            $shipment->booking_source = 'battlefront_simulation';
            $shipment->booking_reference = 'BF-SIM-'.Str::upper(Str::random(12));
            $shipment->save();

            $this->auditOrderChange($lockedOrder, 'shipment.local_booking_simulated', [
                'shipment_id' => $shipment->id,
                'booking_reference' => $shipment->booking_reference,
                'booking_source' => $shipment->booking_source,
            ], $actor);

            return $shipment;
        }, attempts: 3);
    }

    private function localLbcSimulationEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && config('battlefront.shipping.lbc_mock_enabled', false) === true;
    }

    /**
     * Save parcel preparation, handoff, carrier-cost, and exception records.
     *
     * @param  array<string, mixed>  $operations
     */
    public function updateShipmentOperations(Order $order, array $operations, User $actor): Shipment
    {
        return DB::transaction(function () use ($order, $operations, $actor): Shipment {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if ($shipment === null || $shipment->carrier !== 'lbc' || $lockedOrder->fulfillment_method !== FulfillmentMethod::Delivery) {
                throw ValidationException::withMessages([
                    'shipment' => 'Operational shipment details require an existing LBC delivery shipment.',
                ]);
            }

            if ($shipment->handed_to_carrier_at !== null && $shipment->package_count !== null && (
                (string) $shipment->package_count !== (string) $operations['package_count']
                || bccomp((string) $shipment->package_weight_kg, (string) $operations['package_weight_kg'], 3) !== 0
                || bccomp((string) $shipment->package_length_cm, (string) $operations['package_length_cm'], 2) !== 0
                || bccomp((string) $shipment->package_width_cm, (string) $operations['package_width_cm'], 2) !== 0
                || bccomp((string) $shipment->package_height_cm, (string) $operations['package_height_cm'], 2) !== 0
                || bccomp((string) $shipment->declared_value, (string) $operations['declared_value'], 2) !== 0
                || $shipment->handoff_mode !== $operations['handoff_mode']
            )) {
                throw ValidationException::withMessages([
                    'package_count' => 'Package details are locked after carrier handoff.',
                ]);
            }

            $shipment->package_count = (int) $operations['package_count'];
            $shipment->package_weight_kg = (string) $operations['package_weight_kg'];
            $shipment->package_length_cm = (string) $operations['package_length_cm'];
            $shipment->package_width_cm = (string) $operations['package_width_cm'];
            $shipment->package_height_cm = (string) $operations['package_height_cm'];
            $shipment->declared_value = (string) $operations['declared_value'];
            $shipment->handoff_mode = (string) $operations['handoff_mode'];

            $carrierCost = $operations['actual_carrier_cost'] ?? null;
            if ($carrierCost !== null) {
                if ($shipment->carrier_cost_recorded_at !== null && (
                    bccomp($shipment->actual_carrier_cost, (string) $carrierCost, 2) !== 0
                    || $shipment->carrier_cost_reference !== trim((string) $operations['carrier_cost_reference'])
                )) {
                    throw ValidationException::withMessages([
                        'actual_carrier_cost' => 'A recorded carrier cost cannot be changed. Use the reconciliation record to review discrepancies.',
                    ]);
                }

                if ($shipment->carrier_cost_recorded_at === null) {
                    $shipment->actual_carrier_cost = (string) $carrierCost;
                    $shipment->carrier_cost_reference = trim((string) $operations['carrier_cost_reference']);
                    $shipment->carrier_cost_recorded_at = now();
                }
            }

            $exceptionCode = $operations['shipment_exception_code'] ?? null;
            if (filled($exceptionCode)) {
                $exceptionNote = trim((string) $operations['shipment_exception_note']);
                $exceptionChanged = $shipment->shipment_exception_code !== (string) $exceptionCode
                    || $shipment->shipment_exception_note !== $exceptionNote;

                if ($exceptionChanged) {
                    $shipment->shipment_exception_code = (string) $exceptionCode;
                    $shipment->shipment_exception_note = $exceptionNote;
                    $shipment->shipment_exception_reported_at = now();
                    $shipment->shipment_exception_resolved_at = null;
                }
            }

            if ($shipment->shipment_exception_reported_at !== null && filter_var($operations['shipment_exception_resolved'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                if ($shipment->shipment_exception_resolved_at === null) {
                    $shipment->shipment_exception_resolved_at = now();
                }
            }

            $shipment->save();

            $this->auditOrderChange($lockedOrder, 'shipment.operations.updated', [
                'shipment_id' => $shipment->id,
                'package_count' => $shipment->package_count,
                'handoff_mode' => $shipment->handoff_mode,
                'carrier_cost_recorded' => $shipment->carrier_cost_recorded_at !== null,
                'exception_code' => $shipment->shipment_exception_code,
                'exception_resolved' => $shipment->shipment_exception_resolved_at !== null,
            ], $actor);

            return $shipment;
        }, attempts: 3);
    }

    public function createManualShipment(
        Order $order,
        string $carrier,
        ?string $trackingReference,
        ?int $etaMinimumDays,
        ?int $etaMaximumDays,
        bool $arrangementConfirmed,
        ?User $actor = null,
    ): Shipment {
        return DB::transaction(function () use (
            $order,
            $carrier,
            $trackingReference,
            $etaMinimumDays,
            $etaMaximumDays,
            $arrangementConfirmed,
            $actor,
        ): Shipment {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $arrangementConfirmed) {
                throw ValidationException::withMessages([
                    'arrangement_confirmed' => 'Confirm that LBC has accepted the manual shipping arrangement.',
                ]);
            }

            if (! $this->canCreateManualShipment($lockedOrder)) {
                throw ValidationException::withMessages([
                    'carrier' => 'Manual shipments are available only for eligible, unconfigured delivery orders without an existing shipment.',
                ]);
            }

            $normalizedCarrier = mb_strtolower(trim($carrier));
            if ($normalizedCarrier !== 'lbc') {
                throw ValidationException::withMessages([
                    'carrier' => 'This manual shipment workflow requires an explicitly arranged LBC delivery.',
                ]);
            }

            if (
                ($etaMinimumDays === null) !== ($etaMaximumDays === null)
                || ($etaMinimumDays !== null && ($etaMinimumDays < 0 || $etaMaximumDays < $etaMinimumDays))
            ) {
                throw ValidationException::withMessages([
                    'eta_min_days' => 'Enter both estimated delivery day values, or leave both blank when no estimate is available.',
                ]);
            }

            $shipment = $lockedOrder->shipment()->create([
                'arrangement_type' => ShipmentArrangement::Manual,
                'carrier' => $normalizedCarrier,
                'status' => ShipmentStatus::AwaitingPreparation,
                'preparation_days' => null,
                'transit_min_days' => null,
                'transit_max_days' => null,
                'eta_min_days' => $etaMinimumDays,
                'eta_max_days' => $etaMaximumDays,
                'tracking_reference' => $this->normalizeTrackingReference($trackingReference),
            ]);

            $this->auditOrderChange($lockedOrder, 'shipment.manual_created', ['shipment_id' => $shipment->id], $actor);

            $this->notifications->afterCommit($lockedOrder, 'shipment.manual_created');

            return $shipment;
        }, attempts: 3);
    }

    public function canUpdateShipmentReference(Order $order): bool
    {
        $shipment = $order->shipment;

        return $order->fulfillment_method === FulfillmentMethod::Delivery
            && $shipment !== null
            && $this->paymentAllowsFulfillment($order)
            && in_array($shipment->status, [ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], strict: true)
            && ($order->status === OrderStatus::Processing
                || ($order->status === OrderStatus::Completed && $shipment->status === ShipmentStatus::Delivered));
    }

    public function updateShipmentStatus(Order $order, ShipmentStatus $status, ?string $trackingReference = null, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $status, $trackingReference, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if ($this->nextShipmentStatus($lockedOrder) !== $status) {
                throw ValidationException::withMessages([
                    'status' => 'Shipment progression requires verified prepaid payment or pending COD, a processing delivery order, and the next milestone only.',
                ]);
            }

            if ($shipment === null) {
                throw ValidationException::withMessages(['status' => 'This order has no shipment.']);
            }

            if ($status === ShipmentStatus::HandedToLbc && (
                $shipment->package_count === null
                || $shipment->package_weight_kg === null
                || $shipment->package_length_cm === null
                || $shipment->package_width_cm === null
                || $shipment->package_height_cm === null
                || $shipment->declared_value === null
                || $shipment->handoff_mode === null
            )) {
                throw ValidationException::withMessages([
                    'status' => 'Record package count, weight, dimensions, declared value, and LBC handoff mode before confirming carrier handoff.',
                ]);
            }

            $changes = ['status' => $status, $status->timestampColumn() => now()];
            if (
                $status === ShipmentStatus::Preparing
                && $shipment->eta_min_days !== null
                && $shipment->eta_max_days !== null
            ) {
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
            $this->auditOrderChange($lockedOrder, 'shipment.status.updated', ['status' => $status->value], $actor);

            if ($status === ShipmentStatus::Delivered && $lockedOrder->payment_method !== PaymentMethod::Cod) {
                $this->transitionLockedOrder($lockedOrder, OrderStatus::Completed, $shipment, actor: $actor);
            }

            $this->notifications->afterCommit($lockedOrder, 'shipment.'.$status->value);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function updateShipmentReference(Order $order, ?string $trackingReference, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $trackingReference, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $this->canUpdateShipmentReference($lockedOrder) || $shipment === null) {
                throw ValidationException::withMessages(['tracking_reference' => 'References can only be maintained for eligible shipments handed to LBC.']);
            }

            $shipment->update(['tracking_reference' => $this->normalizeTrackingReference($trackingReference)]);
            $this->auditOrderChange($lockedOrder, 'shipment.reference.updated', ['tracking_reference' => $shipment->tracking_reference], $actor);

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

    private function transitionLockedOrder(
        Order $order,
        OrderStatus $status,
        ?Shipment $shipment,
        UserRole $cancellationInitiator = UserRole::Administrator,
        ?User $actor = null,
        bool $productsHandedOver = false,
    ): void {
        if ($status === OrderStatus::Completed && $order->fulfillment_method === FulfillmentMethod::Pickup && ! $productsHandedOver) {
            throw ValidationException::withMessages(['products_handed_over_confirmed' => 'Confirm actual product handover before completing pickup.']);
        }
        if ($status === OrderStatus::Cancelled) {
            $blockedReason = $this->shipmentCancellationBlockedReason($shipment);
            if ($blockedReason !== null) {
                throw ValidationException::withMessages(['status' => $blockedReason]);
            }
        }

        if (! $order->status->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => 'This order cannot move to the selected status.']);
        }

        if (($status === OrderStatus::Processing && ! $this->paymentAllowsFulfillment($order))
            || ($status === OrderStatus::Completed && $order->payment_status !== PaymentStatus::Verified)) {
            throw ValidationException::withMessages(['status' => 'Verify payment before processing or completing this order.']);
        }

        if ($status === OrderStatus::Completed && $shipment !== null && $shipment->status !== ShipmentStatus::Delivered) {
            throw ValidationException::withMessages(['status' => 'Complete delivery through the shipment Delivered milestone.']);
        }

        if ($status === OrderStatus::Completed && $order->handler_required && $order->assigned_handler_id === null) {
            throw ValidationException::withMessages(['status' => 'Assign a responsible staff member before completing this order.']);
        }

        if ($status === OrderStatus::Cancelled) {
            $this->restoreOrderInventory->execute($order);
            $shipment?->update(['status' => ShipmentStatus::Cancelled, 'cancelled_at' => now()]);
        }

        $previousStatus = $order->status->value;
        if ($status === OrderStatus::Completed && $order->fulfillment_method === FulfillmentMethod::Pickup) {
            $order->pickup_handed_over_at = CarbonImmutable::now('UTC');
        }
        $order->update(['status' => $status]);
        $this->auditOrderChange($order, 'order.status.updated', ['before' => $previousStatus, 'after' => $status->value], $actor);
        if ($status === OrderStatus::Cancelled) {
            $this->notifications->afterCommit($order, 'order.cancelled', $cancellationInitiator);
        }
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
        ?User $actor = null,
    ): Order {
        return DB::transaction(function () use ($order, $paymentStatus, $rejectionReason, $rejectionNote, $actor): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->payment_origin === 'paymongo') {
                throw ValidationException::withMessages([
                    'payment_status' => 'PayMongo payments can only be confirmed from authenticated provider events.',
                ]);
            }

            $this->authorizeHandler($lockedOrder, $actor);
            if ($lockedOrder->payment_method === PaymentMethod::Cod) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Use Confirm COD Collection after delivery. COD does not accept ordinary payment verification or proof rejection.',
                ]);
            }

            if ($lockedOrder->status === OrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Cancelled orders cannot accept payment decisions.',
                ]);
            }

            if ($lockedOrder->payment_method->isPayAtPickup()) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Use Confirm Payment & Complete Pickup. Cash/Card pickup does not accept separate payment verification or proof rejection.',
                ]);
            }

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

            $this->auditOrderChange($lockedOrder, 'order.payment_status.updated', ['after' => $paymentStatus->value], $actor);

            $this->notifications->afterCommit($lockedOrder, 'payment.'.$paymentStatus->value);

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

    private function isCodDelivery(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::Cod
            && $order->fulfillment_method === FulfillmentMethod::Delivery
            && $order->delivery_destination !== null
            && $order->payment_proof_path === null
            && $order->shipment?->carrier === 'lbc';
    }

    private function paymentAllowsFulfillment(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Verified
            || ($order->payment_status === PaymentStatus::Pending && ($this->isCodDelivery($order) || $this->isPayAtPickupOrder($order)));
    }

    private function payMongoCancellationBlockedMessage(Order $order): string
    {
        $order->refresh();

        if ($order->payment_status === PaymentStatus::Verified) {
            return 'PayMongo confirmed your payment, so this order was not cancelled. Cancellation does not refund a payment. Contact Battlefront for help with this paid order.';
        }

        return 'PayMongo still reports a possible payment. The order remains pending and inventory stays reserved while payment is reconciled.';
    }

    private function isPayAtPickupOrder(Order $order): bool
    {
        return $order->payment_method->isPayAtPickup()
            && $order->fulfillment_method === FulfillmentMethod::Pickup
            && $order->payment_proof_path === null
            && $order->shipment === null;
    }

    public function canConfirmPickup(Order $order): bool
    {
        return $this->isPayAtPickupOrder($order)
            && $order->status === OrderStatus::Processing
            && in_array($order->payment_status, [PaymentStatus::Pending, PaymentStatus::Verified], strict: true);
    }

    public function confirmPickup(Order $order, bool $paymentReceived, bool $productsHandedOver, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $paymentReceived, $productsHandedOver, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $paymentReceived || ! $productsHandedOver || ! $this->canConfirmPickup($lockedOrder)) {
                throw ValidationException::withMessages([
                    'payment_received_confirmed' => 'Confirm payment received and products handed over for an eligible Processing Cash/Card pickup order.',
                ]);
            }

            $paymentWasPending = $lockedOrder->payment_status === PaymentStatus::Pending;
            $lockedOrder->update([
                'payment_status' => PaymentStatus::Verified,
                'payment_rejection_reason' => null,
                'payment_rejection_note' => null,
            ]);
            $this->auditOrderChange($lockedOrder, 'order.payment_status.updated', ['after' => PaymentStatus::Verified->value], $actor);
            $this->transitionLockedOrder($lockedOrder, OrderStatus::Completed, $shipment, actor: $actor, productsHandedOver: $productsHandedOver);
            if ($paymentWasPending) {
                $this->notifications->afterCommit($lockedOrder, 'payment.verified');
            }

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function canConfirmCodCollection(Order $order): bool
    {
        return $this->isCodDelivery($order)
            && $order->status === OrderStatus::Processing
            && $order->payment_status === PaymentStatus::Pending
            && $order->shipment?->status === ShipmentStatus::Delivered
            && $order->shipment->carrier_cod_collected_at === null;
    }

    public function confirmCodCollection(Order $order, bool $collectionConfirmed, ?string $collectionReference = null, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $collectionConfirmed, $collectionReference, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $collectionConfirmed || ! $this->canConfirmCodCollection($lockedOrder)) {
                throw ValidationException::withMessages([
                    'cod_collection_confirmed' => 'Explicitly confirm verified LBC collection for a delivered COD order with pending payment and Processing status.',
                ]);
            }

            $shipment->carrier_cod_collected_amount = $lockedOrder->total_amount;
            $shipment->carrier_cod_collection_reference = filled($collectionReference) ? trim($collectionReference) : null;
            $shipment->carrier_cod_collected_at = now();
            $shipment->save();
            $this->auditOrderChange($lockedOrder, 'shipment.carrier_cod_collected', [
                'shipment_id' => $shipment->id,
                'amount' => $shipment->carrier_cod_collected_amount,
                'collection_reference' => $shipment->carrier_cod_collection_reference,
            ], $actor);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function canConfirmCodRemittance(Order $order): bool
    {
        return $this->isCodDelivery($order)
            && $order->status === OrderStatus::Processing
            && $order->payment_status === PaymentStatus::Pending
            && $order->shipment?->status === ShipmentStatus::Delivered
            && $order->shipment->carrier_cod_collected_at !== null
            && $order->shipment->carrier_cod_remitted_at === null;
    }

    public function confirmCodRemittance(Order $order, bool $remittanceConfirmed, string $reference, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $remittanceConfirmed, $reference, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $remittanceConfirmed || ! $this->canConfirmCodRemittance($lockedOrder)) {
                throw ValidationException::withMessages([
                    'cod_remittance_confirmed' => 'Confirm LBC remitted the recorded COD collection for a delivered order.',
                ]);
            }

            if (bccomp((string) $shipment->carrier_cod_collected_amount, $lockedOrder->total_amount, 2) !== 0) {
                throw ValidationException::withMessages([
                    'cod_remittance_confirmed' => 'The recorded carrier collection does not match the saved order total. Reconcile the discrepancy before recording remittance.',
                ]);
            }

            $shipment->carrier_cod_remitted_amount = $lockedOrder->total_amount;
            $shipment->carrier_cod_remittance_reference = trim($reference);
            $shipment->carrier_cod_remitted_at = now();
            $shipment->save();

            $lockedOrder->update([
                'payment_status' => PaymentStatus::Verified,
                'payment_rejection_reason' => null,
                'payment_rejection_note' => null,
            ]);
            $this->auditOrderChange($lockedOrder, 'shipment.carrier_cod_remitted', [
                'shipment_id' => $shipment->id,
                'amount' => $shipment->carrier_cod_remitted_amount,
                'reference' => $shipment->carrier_cod_remittance_reference,
            ], $actor);
            $this->auditOrderChange($lockedOrder, 'order.payment_status.updated', ['after' => PaymentStatus::Verified->value], $actor);
            $this->transitionLockedOrder($lockedOrder, OrderStatus::Completed, $shipment, actor: $actor);
            $this->notifications->afterCommit($lockedOrder, 'payment.verified');

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function canProcess(Order $order, User $actor): bool
    {
        return $actor->hasAdministrationPermission('orders.process')
            && $order->assigned_handler_id === $actor->id;
    }

    /**
     * Administrator entry points supply an actor; trusted internal transitions may omit it.
     * Call only after acquiring the order lock so reassignment cannot invalidate this check.
     */
    private function authorizeHandler(Order $order, ?User $actor): void
    {
        if ($actor !== null) {
            abort_unless($this->canProcess($order, $actor), 403, 'Only the assigned handler may process this order. Refresh the order to review its current handler.');
        }
    }

    /** @param array<string, mixed> $changes */
    private function auditOrderChange(Order $order, string $action, array $changes, ?User $actor = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
