<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\OrderIndexRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Repositories\Order\AdministratorOrderRepository;
use App\Services\DeliveryQuotePresenter;
use App\Services\Order\OrderProcessingService;
use App\Services\ShipmentPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(private readonly AdministratorOrderRepository $orderRepository) {}

    /**
     * Display the administrator order directory.
     */
    public function index(OrderIndexRequest $request): Response
    {
        $filters = $request->validated();
        $statusFilter = $filters['status'] ?? 'active';
        $filters['status'] = $statusFilter;

        $directory = $this->orderRepository->directory($filters, $statusFilter);
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

        return Inertia::render('Administration/Orders/Index', [
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
        ]);
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
     * Display complete processing details for an order.
     */
    public function show(Request $request, Order $order, OrderProcessingService $processing, ShipmentPresenter $shipments, DeliveryQuotePresenter $quotes): Response
    {
        $order = $this->orderRepository->loadDetails($order);
        $nextShipmentStatus = $processing->nextShipmentStatus($order);
        $canViewProfitability = $request->user()->can('reports.profit');
        $orderHasSale = DB::table('sales')->where('order_id', $order->id)->exists();
        $salesReturns = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.sales_return_amount');
        $costRecovered = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.cost_recovered_amount');
        $costOfGoodsSold = '0.00';
        $hasMissingCost = $order->items->isEmpty();
        foreach ($order->items as $item) {
            if ($item->acquisition_cost_at_time === null) {
                $hasMissingCost = true;

                continue;
            }
            $costOfGoodsSold = bcadd($costOfGoodsSold, bcmul($item->acquisition_cost_at_time, (string) $item->quantity, 2), 2);
        }
        $productRevenue = $order->product_subtotal ?? $order->items->reduce(
            fn (string $total, OrderItem $item): string => bcadd(
                $total,
                bcmul($item->price_at_time, (string) $item->quantity, 2),
                2,
            ),
            '0.00',
        );
        $grossProfit = ! $hasMissingCost && $orderHasSale && $order->status === OrderStatus::Completed
            ? bcsub(bcsub($productRevenue, $salesReturns, 2), bcsub($costOfGoodsSold, $costRecovered, 2), 2)
            : null;

        return Inertia::render('Administration/Orders/Show', [
            'handler_options' => $order->status === OrderStatus::Completed || $order->status === OrderStatus::Cancelled
                ? []
                : User::query()->where('role', UserRole::Administrator->value)
                    ->where('is_active', true)
                    ->whereIn('administration_role_id', DB::table('administration_role_permissions as grants')
                        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                        ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                        ->where('permissions.code', 'orders.process')->where('roles.code', '!=', 'it')
                        ->select('grants.administration_role_id'))
                    ->orderBy('name')->get(['id', 'name']),
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'handler_required' => $order->handler_required,
                'assigned_handler_id' => $order->assigned_handler_id,
                'assigned_handler_name' => $order->assigned_handler_id === null ? null : User::query()->whereKey($order->assigned_handler_id)->value('name'),
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
                'delivery_quote' => $quotes->stored($order),
                'shipment' => $shipments->detail($order),
                'can_create_manual_shipment' => $processing->canCreateManualShipment($order),
                'shipment_actions' => $order->shipment === null ? null : [
                    'next_status' => $nextShipmentStatus === null ? null : [
                        'value' => $nextShipmentStatus->value,
                        'label' => $nextShipmentStatus->label(),
                    ],
                    'can_update_reference' => $processing->canUpdateShipmentReference($order),
                ],
                'allowed_status_transitions' => collect($processing->allowedOrderTransitions($order))
                    ->map(fn (OrderStatus $status): array => $this->statusData($status))
                    ->all(),
                'cancellation_blocked_reason' => $processing->shipmentCancellationBlockedReason($order->shipment),
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
                    'can_confirm_cod_collection' => $processing->canConfirmCodCollection($order),
                    'pay_at_pickup' => $order->payment_method->isPayAtPickup(),
                    'can_confirm_pickup' => $processing->canConfirmPickup($order),
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
                ...($canViewProfitability ? [
                    'profitability' => [
                        'sales_returns' => $salesReturns,
                        'cost_of_goods_sold' => $costOfGoodsSold,
                        'gross_profit' => $grossProfit,
                        'available' => $grossProfit !== null,
                    ],
                ] : []),
                'item_count' => $order->items->count(),
                'total_quantity' => $order->items->sum('quantity'),
                'product_subtotal' => $order->product_subtotal,
                'delivery_fee' => $order->delivery_fee,
                'total' => $order->total_amount,
            ],
        ]);
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
