<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\OrderIndexRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class OrderController extends Controller
{
    /**
     * Display the administrator order directory.
     */
    public function index(OrderIndexRequest $request): Response
    {
        $filters = $request->validated();
        $statusFilter = $filters['status'] ?? 'active';
        $filters['status'] = $statusFilter;

        $filteredOrders = $this->applyIndexFilters(Order::query(), $filters);
        $countsByStatus = (clone $filteredOrders)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $orders = $this->applyStatusFilter($filteredOrders, $statusFilter)
            ->select([
                'id', 'user_id', 'recipient_name', 'fulfillment_method',
                'total_amount', 'status', 'payment_status', 'payment_method', 'created_at',
            ])
            ->with('user:id,name,email')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($filters)
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
                    ],
                    'status' => [
                        'value' => $order->payment_status->value,
                        'label' => $order->payment_status->label(),
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
     * Apply filters shared by each administrator order view.
     *
     * @param  Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function applyIndexFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $referenceId = $this->referenceIdFromSearch($search);

                $query->where(function (Builder $query) use ($referenceId, $search): void {
                    $query
                        ->where('recipient_name', 'like', "%{$search}%")
                        ->orWhereHas(
                            'user',
                            fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"),
                        );

                    if ($referenceId !== null) {
                        $query->orWhereKey($referenceId);
                    }
                });
            })
            ->when(
                $filters['payment_status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('payment_status', $status),
            )
            ->when(
                $filters['payment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('payment_method', $method),
            )
            ->when(
                $filters['fulfillment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('fulfillment_method', $method),
            );
    }

    /**
     * Limit the administrator directory to the selected operational view.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'completed' => $query->where('status', OrderStatus::Completed->value),
            'cancelled' => $query->where('status', OrderStatus::Cancelled->value),
            default => $query->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Processing->value,
            ]),
        };
    }

    /**
     * Resolve the numeric identifier from a complete public order reference.
     */
    private function referenceIdFromSearch(string $search): ?int
    {
        if (preg_match('/^BF-(\d+)$/i', trim($search), $matches) !== 1) {
            return null;
        }

        $referenceId = (int) $matches[1];

        return $referenceId > 0 ? $referenceId : null;
    }

    /**
     * Describe active fulfillment work without changing persisted statuses.
     */
    private function fulfillmentOperationalLabel(Order $order): string
    {
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
    public function show(Order $order): Response
    {
        $order->load([
            'user:id,name,email',
            'items' => fn ($query) => $query
                ->select(['id', 'order_id', 'product_id', 'quantity', 'price_at_time'])
                ->orderBy('id'),
            'items.product:id,name,brand,image_url',
        ]);

        return Inertia::render('Administration/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
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
                ],
                'status' => $this->statusData($order->status),
                'allowed_status_transitions' => collect($order->status->allowedTransitions())
                    ->map(fn (OrderStatus $status): array => $this->statusData($status))
                    ->all(),
                'payment' => [
                    'method' => [
                        'value' => $order->payment_method->value,
                        'label' => $order->payment_method->label(),
                        'requires_proof' => $order->payment_method->requiresPaymentProof(),
                    ],
                    'status' => [
                        'value' => $order->payment_status->value,
                        'label' => $order->payment_status->label(),
                    ],
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
