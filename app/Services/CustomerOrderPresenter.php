<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use InvalidArgumentException;

class CustomerOrderPresenter
{
    public function __construct(
        private readonly DeliveryQuotePresenter $deliveryQuotePresenter,
        private readonly ShipmentPresenter $shipmentPresenter,
    ) {}

    /**
     * Build a compact order record for the customer history.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return [
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
                ],
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->payment_status->label(),
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
        return [
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
                ],
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->payment_status->label(),
                ],
                'proof_submitted' => $order->payment_proof_path !== null,
                'notice' => $this->paymentNotice($order),
                'rejection' => $this->paymentRejectionData($order),
                'can_resubmit_proof' => $this->canResubmitPaymentProof($order),
            ],
            'items' => $order->items->map(function (OrderItem $item): array {
                $unitPrice = $item->price_at_time;

                if (! is_numeric($unitPrice)) {
                    throw new InvalidArgumentException('Order item prices must be numeric.');
                }

                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'brand' => $item->product->brand,
                        'image_url' => $item->product->image_url,
                    ],
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => bcmul($unitPrice, (string) $item->quantity, 2),
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
        if (! $order->payment_method->requiresPaymentProof()) {
            return 'Payment will be handled when you collect your order.';
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
            'label' => $order->status->customerLabel($order->fulfillment_method),
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
        return $order->payment_method->requiresPaymentProof()
            && $order->payment_status === PaymentStatus::Rejected
            && ! in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], strict: true);
    }
}
