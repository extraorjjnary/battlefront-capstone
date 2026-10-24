<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Order\OrderProcessingService;
use App\Support\OperationalRequestReason;

class CustomerOrderPresenter
{
    public function __construct(
        private readonly DeliveryQuotePresenter $deliveryQuotePresenter,
        private readonly ShipmentPresenter $shipmentPresenter,
        private readonly OrderProcessingService $orderProcessingService,
        private readonly CustomerReturnEligibility $returnEligibility,
        private readonly CustomerReturnRequestPresenter $returnRequests,
        private readonly BranchDirectory $branches,
        private readonly OrderCancellationPresenter $cancellations,
    ) {}

    /**
     * Build a compact order record for the customer history.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return [
            'cancellation' => $this->cancellations->summary($order),
            'id' => $order->id,
            'reference' => $order->reference,
            'created_at' => $order->created_at->toIso8601String(),
            'status' => $this->customerStatusData($order),
            'fulfillment' => [
                'value' => $order->fulfillment_method->value,
                'label' => $order->fulfillment_method->label(),
            ],
            'payment' => [
                'method' => [
                    'value' => $order->payment_method->value,
                    'label' => $order->payment_method->label(),
                    ...($order->payment_method->isPayAtPickup() ? ['description' => $order->payment_method->description()] : []),
                ],
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->paymentStatusLabel(),
                ],
            ],
            'item_count' => (int) $order->getAttribute('items_count'),
            'total_quantity' => (int) ($order->getAttribute('total_quantity') ?? 0),
            'total' => $order->total_amount,
        ];
    }

    /**
     * Build the customer-safe order confirmation payload.
     *
     * @return array<string, mixed>
     */
    public function detail(Order $order): array
    {
        $returnItems = collect($this->returnEligibility->forOrder($order));
        $refundPayments = $this->returnRequests->paymentsForOrder($order);

        return [
            'can_request_return' => $returnItems->contains(fn (array $item): bool => $item['can_request']),
            'can_request_cancellation' => $this->orderProcessingService->canRequestCancellation($order),
            'cancellation' => $this->cancellations->detail($order),
            'return_reason_options' => OperationalRequestReason::customerReturnOptions(),
            'return_requests' => $this->returnRequests->forOrder($order, $refundPayments),
            'refund_payments' => array_merge([], ...array_values($refundPayments)),
            'return_instructions' => $this->branches->returnInstructions(),
            'return_eligibility' => $this->returnEligibility->window($order),
            'can_cancel' => $this->orderProcessingService->canCustomerCancel($order),
            'product_subtotal' => $order->product_subtotal,
            'delivery_fee' => $order->delivery_fee,
            'delivery_quote' => $this->deliveryQuotePresenter->stored($order),
            'shipment' => $this->shipmentPresenter->detail($order),
            'id' => $order->id,
            'reference' => $order->reference,
            'created_at' => $order->created_at->toIso8601String(),
            'status' => $this->customerStatusData($order),
            'recipient' => [
                'name' => $order->recipient_name,
                'contact_number' => $order->contact_number,
            ],
            'fulfillment' => [
                'value' => $order->fulfillment_method->value,
                'label' => $order->fulfillment_method->label(),
                'delivery_address' => $order->delivery_address,
                'delivery_destination' => $order->delivery_destination,
            ],
            'payment' => [
                'method' => [
                    'value' => $order->payment_method->value,
                    'label' => $order->payment_method->label(),
                    ...($order->payment_method->isPayAtPickup() ? ['description' => $order->payment_method->description()] : []),
                ],
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->paymentStatusLabel(),
                ],
                'proof_submitted' => $order->payment_proof_path !== null,
                'notice' => $this->paymentNotice($order),
                'rejection' => $this->paymentRejectionData($order),
                'can_resubmit_proof' => $this->canResubmitPaymentProof($order),
            ],
            'items' => $order->items->map(function (OrderItem $item) use ($returnItems): array {
                $unitPrice = $item->price_at_time;

                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->purchasedProductName(),
                        'brand' => $item->product->brand,
                        'image_url' => $item->product->image_url,
                    ],
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => bcmul($unitPrice, (string) $item->quantity, 2),
                    ...$returnItems[$item->id],
                ];
            })->values()->all(),
            'item_count' => $order->items->count(),
            'total_quantity' => $order->items->sum('quantity'),
            'total' => $order->total_amount,
        ];
    }

    /**
     * Explain the current persisted payment state without exposing evidence.
     */
    private function paymentNotice(Order $order): string
    {
        if ($this->orderProcessingService->hasCancellationHold($order)) {
            return 'Cancellation requested. Order handling and payment-proof changes are paused for review.';
        }
        if ($order->status === OrderStatus::Cancelled && $order->cancellationRequest !== null) {
            return 'This order is cancelled. The original payment remains recorded separately; see the cancellation refund status.';
        }
        if ($order->status === OrderStatus::Cancelled) {
            return 'This order is cancelled. Cancellation does not refund or reverse any recorded payment.';
        }

        if ($order->payment_method === PaymentMethod::Cod) {
            if (($order->delivery_quote_metadata['pricing_status'] ?? null) === 'not_configured') {
                return 'The product subtotal is payable upon delivery. No delivery fee is included, and no LBC booking or carrier collection arrangement is represented.';
            }

            if ($order->payment_status === PaymentStatus::Verified) {
                return 'Battlefront has manually confirmed that LBC collected your COD payment.';
            }

            return $order->shipment?->status === ShipmentStatus::Delivered
                ? 'Delivery is recorded. Your order remains Processing until Battlefront manually confirms LBC collected the COD payment.'
                : 'The total COD amount, including the configured delivery fee, is payable upon delivery. No advance payment or uploaded proof is required. LBC booking and collection are managed manually by Battlefront.';
        }

        if ($order->payment_method->isPayAtPickup()) {
            if ($order->status === OrderStatus::Completed && $order->payment_status === PaymentStatus::Verified) {
                return 'Battlefront has confirmed your payment and completed pickup.';
            }

            if ($order->payment_status === PaymentStatus::Verified) {
                return 'Your payment is already verified. Battlefront must confirm product handover before completing pickup.';
            }

            return $order->payment_method->description().' Battlefront confirms payment and product handover together to complete pickup.';
        }

        if ($order->payment_proof_path === null) {
            return 'No payment proof is recorded for this order.';
        }

        return match ($order->payment_status) {
            PaymentStatus::Pending => 'Your uploaded proof is awaiting manual verification by Battlefront.',
            PaymentStatus::Verified => 'Your payment has been manually verified by Battlefront.',
            PaymentStatus::Rejected => 'Your submitted payment proof was rejected. Upload a replacement for another manual review.',
        };
    }

    /**
     * Build fulfillment-aware status wording for customer order views.
     *
     * @return array{value: string, label: string}
     */
    private function customerStatusData(Order $order): array
    {
        return [
            'value' => $order->status->value,
            'label' => $order->customerStatusLabel(),
        ];
    }

    /**
     * Build safe customer-facing rejection feedback.
     *
     * @return array{reason: string, note: string|null}|null
     */
    private function paymentRejectionData(Order $order): ?array
    {
        if ($order->payment_status !== PaymentStatus::Rejected) {
            return null;
        }

        $rejectionNote = filled($order->payment_rejection_note)
            ? trim($order->payment_rejection_note)
            : null;
        $rejectionReason = $order->payment_rejection_reason;

        if ($rejectionReason === null || ($rejectionReason === PaymentRejectionReason::Other && $rejectionNote === null)) {
            return [
                'reason' => 'Battlefront could not verify the submitted payment proof.',
                'note' => null,
            ];
        }

        return [
            'reason' => $rejectionReason->label(),
            'note' => $rejectionNote,
        ];
    }

    /**
     * Determine whether the customer can submit replacement evidence.
     */
    private function canResubmitPaymentProof(Order $order): bool
    {
        return ! $this->orderProcessingService->hasCancellationHold($order)
            && $order->payment_method->requiresPaymentProof()
            && $order->payment_status === PaymentStatus::Rejected
            && ! in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], strict: true);
    }
}
