<?php

namespace App\Services;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Repositories\Order\AdministratorOrderRepository;
use App\Services\Order\OrderProcessingService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdministratorOrderPresenter
{
    public function __construct(
        private readonly AdministratorOrderRepository $orderRepository,
        private readonly AdministratorOrderProfitability $profitability,
        private readonly OrderProcessingService $processing,
        private readonly ShipmentPresenter $shipments,
        private readonly DeliveryQuotePresenter $quotes,
        private readonly OrderCancellationPresenter $cancellations,
    ) {}

    /**
     * @param  array{orders: LengthAwarePaginator<int, Order>, counts: Collection<string, int>}  $directory
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function directory(array $directory, array $filters, string $statusFilter): array
    {
        $countsByStatus = $directory['counts'];
        $orders = $directory['orders']
            ->through(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'created_at' => $order->created_at->toIso8601String(),
                'customer' => [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ],
                'recipient_name' => $order->recipient_name,
                'fulfillment' => [
                    'value' => $order->fulfillment_method->value,
                    'label' => $order->fulfillment_method->label(),
                    'operational_label' => $this->fulfillmentOperationalLabel($order),
                ],
                'status' => $this->statusData($order->status),
                'cancellation' => $this->cancellations->summary($order),
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
            ]);

        return [
            'orders' => $orders,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'status' => $statusFilter,
                'payment_status' => $filters['payment_status'] ?? null,
                'payment_method' => $filters['payment_method'] ?? null,
                'fulfillment_method' => $filters['fulfillment_method'] ?? null,
            ],
            'filter_options' => [
                'payment_statuses' => collect(PaymentStatus::cases())
                    ->map(fn (PaymentStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ])->all(),
                'payment_methods' => collect(PaymentMethod::cases())
                    ->map(fn (PaymentMethod $method): array => [
                        'value' => $method->value,
                        'label' => $method->label(),
                    ])->all(),
                'fulfillment_methods' => collect(FulfillmentMethod::cases())
                    ->map(fn (FulfillmentMethod $method): array => [
                        'value' => $method->value,
                        'label' => $method->label(),
                    ])->all(),
            ],
            'status_counts' => [
                'active' => (int) $countsByStatus->get(OrderStatus::Pending->value, 0)
                    + (int) $countsByStatus->get(OrderStatus::Processing->value, 0),
                'completed' => (int) $countsByStatus->get(OrderStatus::Completed->value, 0),
                'cancelled' => (int) $countsByStatus->get(OrderStatus::Cancelled->value, 0),
            ],
        ];
    }

    /**
     * Describe active fulfillment work without changing persisted statuses.
     */
    private function fulfillmentOperationalLabel(Order $order): string
    {
        if ($order->isDeliveredCodAwaitingCollection()) {
            return $order->customerStatusLabel();
        }

        if ($order->status !== OrderStatus::Processing) {
            return $order->fulfillment_method->label();
        }

        return match ($order->fulfillment_method) {
            FulfillmentMethod::Pickup => 'Preparing for pickup',
            FulfillmentMethod::Delivery => 'Preparing for delivery',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Order $order, User $actor): array
    {
        $canProcess = $this->processing->canProcess($order, $actor);
        $nextShipmentStatus = $canProcess ? $this->processing->nextShipmentStatus($order) : null;
        $payMongoFlow = PayMongoFlow::query()->where('order_id', $order->id)->first();
        $canViewProfitability = $actor->can('reports.profit');
        $profitability = $this->profitability->calculate($order, $this->orderRepository->profitabilityInputs($order));

        return [
            'handler_options' => $this->orderRepository->handlerOptions($order),
            'order' => [
                'cancellation' => $this->cancellations->detail($order, $actor),
                'id' => $order->id,
                'reference' => $order->reference,
                'handler_required' => $order->handler_required,
                'can_process' => $canProcess,
                'assigned_handler_id' => $order->assigned_handler_id,
                'assigned_handler_name' => $this->orderRepository->assignedHandlerName($order),
                'created_at' => $order->created_at->toIso8601String(),
                'customer' => [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ],
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
                'status' => $this->statusData($order->status),
                'delivery_quote' => $this->quotes->stored($order),
                'delivery_next_step' => $this->processing->deliveryNextStep($order),
                'shipment' => $this->shipments->detail($order, includeInternal: true),
                'can_create_manual_shipment' => $canProcess && $this->processing->canCreateManualShipment($order),
                'shipment_actions' => $order->shipment === null ? null : [
                    'next_status' => $nextShipmentStatus === null ? null : [
                        'value' => $nextShipmentStatus->value,
                        'label' => $nextShipmentStatus->label(),
                    ],
                    'can_update_reference' => $canProcess && $this->processing->canUpdateShipmentReference($order),
                    'can_simulate_local_lbc_booking' => $canProcess && $this->processing->canSimulateLocalLbcBooking($order),
                    'can_update_package_details' => $canProcess && $this->processing->canUpdateShipmentOperations($order, 'package'),
                    'can_record_carrier_cost' => $canProcess && $this->processing->canUpdateShipmentOperations($order, 'carrier_cost'),
                    'can_update_shipment_exception' => $canProcess && $this->processing->canUpdateShipmentOperations($order, 'exception'),
                ],
                'allowed_status_transitions' => collect($canProcess ? $this->processing->allowedOrderTransitions($order) : [])
                    ->map(fn (OrderStatus $status): array => $this->statusData($status))
                    ->all(),
                'cancellation_blocked_reason' => $this->processing->shipmentCancellationBlockedReason($order->shipment),
                'payment' => [
                    'method' => [
                        'value' => $order->payment_method->value,
                        'label' => $order->payment_method->label(),
                        ...($order->payment_method->isPayAtPickup() ? ['description' => $order->payment_method->description()] : []),
                        'requires_proof' => $order->payment_method->requiresPaymentProof(),
                    ],
                    'status' => [
                        'value' => $order->payment_status->value,
                        'label' => $order->paymentStatusLabel(),
                    ],
                    'gateway' => $payMongoFlow === null ? null : [
                        'channel' => $payMongoFlow->channel,
                        'bank' => $payMongoFlow->bank_code,
                        'status' => $payMongoFlow->status,
                        'merchant_cutoff_at' => $payMongoFlow->merchant_cutoff_at->toIso8601String(),
                    ],
                    'can_confirm_cod_collection' => $canProcess && $this->processing->canConfirmCodCollection($order),
                    'can_confirm_cod_remittance' => $canProcess && $this->processing->canConfirmCodRemittance($order),
                    'pay_at_pickup' => $order->payment_method->isPayAtPickup(),
                    'can_confirm_pickup' => $canProcess && $this->processing->canConfirmPickup($order),
                    'proof_submitted' => $order->payment_proof_path !== null,
                    'proof_available' => $this->paymentProofIsAvailable($order),
                    'rejection' => $order->payment_status === PaymentStatus::Rejected
                        ? [
                            'reason' => $order->payment_rejection_reason?->label(),
                            'note' => $order->payment_rejection_note,
                        ]
                        : null,
                    'rejection_reasons' => collect(PaymentRejectionReason::cases())
                        ->map(fn (PaymentRejectionReason $reason): array => [
                            'value' => $reason->value,
                            'label' => $reason->label(),
                        ])
                        ->all(),
                ],
                'items' => $order->items->map(function (OrderItem $item): array {
                    $unitPrice = $item->price_at_time;

                    return [
                        'id' => $item->id,
                        'pc_build_group' => $item->pc_build_group_uuid === null ? null : [
                            'uuid' => $item->pc_build_group_uuid,
                            'name' => $item->pc_build_name_at_time,
                        ],
                        'pc_build_component_snapshot' => $item->pc_build_component_snapshot,
                        'product' => [
                            'id' => $item->product->id,
                            'name' => $item->purchasedProductName(),
                            'brand' => $item->product->brand,
                            'image_url' => $item->product->image_url,
                        ],
                        'quantity' => $item->quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => bcmul($unitPrice, (string) $item->quantity, 2),
                    ];
                })->values()->all(),
                ...($canViewProfitability ? [
                    'profitability' => $profitability,
                ] : []),
                'item_count' => $order->items->count(),
                'total_quantity' => $order->items->sum('quantity'),
                'product_subtotal' => $order->product_subtotal,
                'delivery_fee' => $order->delivery_fee,
                'total' => $order->total_amount,
            ],
        ];
    }

    /**
     * Build a labeled order status payload.
     *
     * @return array{value: string, label: string}
     */
    private function statusData(OrderStatus $status): array
    {
        return [
            'value' => $status->value,
            'label' => $status->label(),
        ];
    }

    /**
     * Determine whether private evidence can be shown to the administrator.
     */
    private function paymentProofIsAvailable(Order $order): bool
    {
        return is_string($order->payment_proof_path)
            && Str::startsWith($order->payment_proof_path, 'payment-proofs/')
            && Storage::disk('local')->exists($order->payment_proof_path);
    }
}
