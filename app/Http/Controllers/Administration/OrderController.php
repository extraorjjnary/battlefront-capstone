<?php

namespace App\Http\Controllers\Administration;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
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
    public function index(): Response
    {
        $orders = Order::query()
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
            ->withQueryString()
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
        ]);
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
