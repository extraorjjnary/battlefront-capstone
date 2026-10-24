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
use App\Models\Shipment;
use App\Models\User;
use App\Services\Notifications\OrderNotificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderProcessingService
{
    public function __construct(
        private readonly RestoreOrderInventory $restoreOrderInventory,
        private readonly RecordCompletedOrderSale $recordCompletedOrderSale,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    public function canCustomerCancel(Order $order): bool
    {
        return $order->status === OrderStatus::Pending
            && ! $this->hasCancellationHold($order)
            && $order->payment_status === PaymentStatus::Pending
            && (! $order->payment_method->requiresPaymentProof() || $order->payment_proof_path === null)
            && ($order->shipment === null || $order->shipment->status === ShipmentStatus::AwaitingPreparation);
    }

    public function hasCancellationHold(Order $order): bool
    {
        return $order->cancellationRequest?->status === 'pending';
    }

    public function assertNoCancellationReview(Order $order, string $errorField = 'status'): void
    {
        $request = DB::table('order_cancellation_requests')->where('order_id', $order->id)->lockForUpdate()->first();
        if ($request?->status === 'pending') {
            throw ValidationException::withMessages([$errorField => 'Cancellation requested. Order handling is paused until review is complete.']);
        }
    }

    public function canRequestCancellation(Order $order): bool
    {
        return $this->eligibleWalletCancellation($order) && $order->cancellationRequest === null;
    }

    private function eligibleWalletCancellation(Order $order): bool
    {
        return $order->status === OrderStatus::Pending
            && $order->payment_method->requiresPaymentProof()
            && $order->payment_proof_path !== null
            && ($order->shipment === null || $order->shipment->status === ShipmentStatus::AwaitingPreparation);
    }

    public function requestCancellation(User $customer, int $orderId, ?string $note = null): Order
    {
        abort_unless($customer->role === UserRole::Customer, 403);
        Validator::make(['customer_note' => $note], ['customer_note' => ['nullable', 'string', 'max:1000']])->validate();

        return DB::transaction(function () use ($customer, $orderId, $note): Order {
            $order = $customer->orders()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $order->setRelation('shipment', $order->shipment()->lockForUpdate()->first());
            $existing = DB::table('order_cancellation_requests')->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $this->eligibleWalletCancellation($order) || $existing !== null) {
                throw ValidationException::withMessages(['status' => 'Cancellation requests require a Pending GCash/Maya order with submitted proof, no preparation, and no earlier request.']);
            }
            DB::table('order_cancellation_requests')->insert([
                'order_id' => $order->id, 'customer_note' => filled($note) ? trim($note) : null,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditOrderChange($order, 'order.cancellation_requested', ['customer_note' => $note], $customer);
            $this->notifications->afterCommit($order, 'cancellation.requested');

            return $order->refresh();
        }, attempts: 3);
    }

    public function canReviewCancellation(User $actor): bool
    {
        return in_array($actor->administrationRoleCode(), ['manager', 'owner'], true)
            && $actor->hasAdministrationPermission('orders.view')
            && $actor->hasAdministrationPermission('returns.approve');
    }

    public function reviewCancellation(Order $order, User $actor, bool $approve, string $received, string $note, bool $accountChecked): Order
    {
        abort_unless($this->canReviewCancellation($actor), 403);
        Validator::make(['amount_received' => $received, 'decision_note' => $note, 'account_checked' => $accountChecked], [
            'amount_received' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'decision_note' => ['required', 'string', 'min:5', 'max:2000'],
            'account_checked' => ['required', 'accepted'],
        ])->validate();

        if (! is_numeric($received)) {
            throw ValidationException::withMessages(['amount_received' => 'Enter a valid amount received.']);
        }

        return DB::transaction(function () use ($order, $actor, $approve, $received, $note): Order {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canReviewCancellation($actor->fresh()), 403);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);
            $request = DB::table('order_cancellation_requests')->where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            if ($request?->status !== 'pending' || ! $this->eligibleWalletCancellation($lockedOrder)) {
                throw ValidationException::withMessages(['status' => 'This cancellation request is no longer eligible for review.']);
            }
            $amount = bcadd($received, '0.00', 2);
            $refund = $approve && bccomp($amount, $lockedOrder->total_amount, 2) > 0 ? $lockedOrder->total_amount : ($approve ? $amount : '0.00');
            DB::table('order_cancellation_requests')->where('id', $request->id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id,
                'amount_received' => $amount, 'decision_note' => trim($note), 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            if ($approve) {
                $this->transitionLockedOrder($lockedOrder, OrderStatus::Cancelled, $shipment, cancellationInitiator: UserRole::Customer, actor: $actor, notifyCancellation: false);
                if (bccomp($refund, '0.00', 2) > 0) {
                    DB::table('customer_refunds')->insert([
                        'cancellation_request_id' => $request->id, 'approved_by' => $actor->id,
                        'approved_amount' => $refund, 'paid_amount' => '0.00', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $this->auditOrderChange($lockedOrder, $approve ? 'order.cancellation_approved' : 'order.cancellation_rejected', [
                'request_id' => $request->id, 'amount_received' => $amount, 'refund_amount' => $refund,
                'account_checked' => true, 'decision_note' => trim($note),
            ], $actor);
            $this->notifications->afterCommit($lockedOrder, $approve ? 'cancellation.approved' : 'cancellation.rejected', context: [
                'refund_processing' => $approve && bccomp($refund, '0.00', 2) > 0,
            ]);

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function cancelForCustomer(User $customer, int $orderId): Order
    {
        return DB::transaction(function () use ($customer, $orderId): Order {
            $order = $customer->orders()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $shipment = $order->shipment()->lockForUpdate()->first();
            $order->setRelation('shipment', $shipment);
            $this->assertNoCancellationReview($order);

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
        return DB::transaction(function () use ($order, $status, $actor, $productsHandedOver): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);
            if ($status === OrderStatus::Completed && $lockedOrder->payment_method->isPayAtPickup()) {
                throw ValidationException::withMessages(['status' => 'Use Confirm Payment & Complete Pickup after receiving payment and handing over the products.']);
            }
            if ($status === OrderStatus::Completed && ($shipment !== null || $this->requiresManualShipment($lockedOrder) || $lockedOrder->payment_method === PaymentMethod::Cod)) {
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
        if ($this->hasCancellationHold($order)) {
            return [];
        }

        return array_values(array_filter($order->status->allowedTransitions(), function (OrderStatus $status) use ($order): bool {
            if ($status === OrderStatus::Completed && ($order->shipment !== null || $this->requiresManualShipment($order) || $order->payment_method === PaymentMethod::Cod || $order->payment_method->isPayAtPickup())) {
                return false;
            }

            if ($status === OrderStatus::Cancelled) {
                return $this->shipmentCancellationBlockedReason($order->shipment) === null;
            }

            return $status === OrderStatus::Processing
                ? $this->paymentAllowsFulfillment($order)
                : $order->payment_status === PaymentStatus::Verified;
        }));
    }

    public function nextShipmentStatus(Order $order): ?ShipmentStatus
    {
        if ($this->hasCancellationHold($order)
            || $order->fulfillment_method !== FulfillmentMethod::Delivery
            || $order->status !== OrderStatus::Processing
            || ! $this->paymentAllowsFulfillment($order)) {
            return null;
        }

        return $order->shipment?->status->next();
    }

    private function requiresManualShipment(Order $order): bool
    {
        return $order->fulfillment_method === FulfillmentMethod::Delivery
            && ($order->delivery_quote_metadata['pricing_status'] ?? null) === 'not_configured';
    }

    public function canCreateManualShipment(Order $order): bool
    {
        return ! $this->hasCancellationHold($order)
            && $this->requiresManualShipment($order)
            && $order->delivery_fee === '0.00'
            && $order->product_subtotal !== null
            && bccomp($order->total_amount, $order->product_subtotal, 2) === 0
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], strict: true)
            && (! in_array($order->payment_method, [PaymentMethod::GCash, PaymentMethod::Maya], strict: true)
                || $order->status === OrderStatus::Processing)
            && $order->payment_status !== PaymentStatus::Rejected
            && $order->shipment === null;
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
            && blank($shipment->tracking_reference)
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
                if (filled($shipment->tracking_reference)) {
                    throw ValidationException::withMessages(['tracking_reference' => 'The LBC tracking number is read-only once assigned.']);
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

            if ($shipment !== null && filled($shipment->tracking_reference)) {
                throw ValidationException::withMessages(['tracking_reference' => 'The LBC tracking number is read-only once assigned.']);
            }

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
        bool $notifyCancellation = true,
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

        if ($status === OrderStatus::Completed && $this->requiresManualShipment($order)
            && ($shipment === null || $shipment->arrangement_type !== ShipmentArrangement::Manual || $shipment->status !== ShipmentStatus::Delivered)) {
            throw ValidationException::withMessages(['status' => 'Confirm the delivery arrangement and reach the shipment Delivered milestone before completing this order.']);
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
        if ($status === OrderStatus::Cancelled && $notifyCancellation) {
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
            && $order->shipment?->status === ShipmentStatus::Delivered;
    }

    public function confirmCodCollection(Order $order, bool $collectionConfirmed, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $collectionConfirmed, $actor): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeHandler($lockedOrder, $actor);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->first();
            $lockedOrder->setRelation('shipment', $shipment);

            if (! $collectionConfirmed || ! $this->canConfirmCodCollection($lockedOrder)) {
                throw ValidationException::withMessages([
                    'cod_collection_confirmed' => 'Explicitly confirm verified LBC collection for a delivered COD order with pending payment and Processing status.',
                ]);
            }

            $lockedOrder->update([
                'payment_status' => PaymentStatus::Verified,
                'payment_rejection_reason' => null,
                'payment_rejection_note' => null,
            ]);
            $this->auditOrderChange($lockedOrder, 'order.payment_status.updated', ['after' => PaymentStatus::Verified->value], $actor);
            $this->transitionLockedOrder($lockedOrder, OrderStatus::Completed, $shipment, actor: $actor);
            $this->notifications->afterCommit($lockedOrder, 'payment.verified');

            return $lockedOrder->refresh();
        }, attempts: 3);
    }

    public function canProcess(Order $order, User $actor): bool
    {
        return $actor->hasAdministrationPermission('orders.process')
            && ! $this->hasCancellationHold($order)
            && $order->assigned_handler_id === $actor->id;
    }

    /**
     * Administrator entry points supply an actor; trusted internal transitions may omit it.
     * Call only after acquiring the order lock so reassignment cannot invalidate this check.
     */
    private function authorizeHandler(Order $order, ?User $actor): void
    {
        if ($actor !== null) {
            abort_unless($actor->hasAdministrationPermission('orders.process') && $order->assigned_handler_id === $actor->id, 403, 'Only the assigned handler may process this order. Refresh the order to review its current handler.');
        }
        $this->assertNoCancellationReview($order);
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
