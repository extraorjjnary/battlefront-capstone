<?php

namespace App\Http\Controllers;

use App\Actions\Order\OrderPlacementException;
use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class OrderController extends Controller
{
    /**
     * Display the authenticated customer's order history.
     */
    public function index(Request $request): Response
    {
        /** @var User $customer */
        $customer = $request->user();
        $orders = $customer->orders()
            ->select([
                'id',
                'user_id',
                'fulfillment_method',
                'total_amount',
                'status',
                'payment_status',
                'payment_method',
                'created_at',
            ])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Order $order): array => $this->orderSummaryData($order));

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Place an order from the authenticated customer's checkout.
     */
    public function store(
        ValidateCheckoutRequest $request,
        PlaceOrder $placeOrder,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();
        $validated = $request->validated();
        $paymentMethod = PaymentMethod::from($validated['payment_method']);
        $paymentProofPath = $this->storePaymentProof(
            $request->file('payment_proof'),
            $paymentMethod,
        );

        try {
            $order = $placeOrder->execute($customer, [
                'recipient_name' => $validated['recipient_name'],
                'contact_number' => $validated['contact_number'],
                'fulfillment_method' => FulfillmentMethod::from($validated['fulfillment_method']),
                'delivery_address' => $validated['delivery_address'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_proof_path' => $paymentProofPath,
            ]);
        } catch (OrderPlacementException $exception) {
            $this->deletePaymentProof($paymentProofPath);

            throw ValidationException::withMessages([
                'cart' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->deletePaymentProof($paymentProofPath);

            throw $exception;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Order placed successfully.',
        ]);

        return to_route('orders.show', $order)
            ->with('confirmed_order_id', $order->id);
    }

    /**
     * Display a persisted order confirmation owned by the customer.
     */
    public function show(Request $request, int $order): Response
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()
            ->select([
                'id',
                'user_id',
                'recipient_name',
                'contact_number',
                'fulfillment_method',
                'delivery_address',
                'total_amount',
                'status',
                'payment_status',
                'payment_method',
                'payment_proof_path',
                'payment_rejection_reason',
                'payment_rejection_note',
                'created_at',
            ])
            ->with([
                'items' => fn ($query) => $query
                    ->select(['id', 'order_id', 'product_id', 'quantity', 'price_at_time'])
                    ->orderBy('id'),
                'items.product:id,name,brand,image_url',
            ])
            ->whereKey($order)
            ->firstOrFail();

        return Inertia::render('Orders/Show', [
            'order' => $this->orderData($persistedOrder),
            'isConfirmation' => $request->session()->get('confirmed_order_id') === $persistedOrder->id,
        ]);
    }

    /**
     * Build a compact order record for the customer history.
     *
     * @return array<string, mixed>
     */
    private function orderSummaryData(Order $order): array
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
    private function orderData(Order $order): array
    {
        return [
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
        $label = match ($order->status) {
            OrderStatus::Processing => match ($order->fulfillment_method) {
                FulfillmentMethod::Pickup => 'Preparing for pickup',
                FulfillmentMethod::Delivery => 'Preparing for delivery',
            },
            OrderStatus::Completed => match ($order->fulfillment_method) {
                FulfillmentMethod::Pickup => 'Picked up / Completed',
                FulfillmentMethod::Delivery => 'Delivered / Completed',
            },
            default => $order->status->label(),
        };

        return [
            'value' => $order->status->value,
            'label' => $label,
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

    /**
     * Store required e-wallet evidence on the private disk.
     */
    private function storePaymentProof(
        ?UploadedFile $paymentProof,
        PaymentMethod $paymentMethod,
    ): ?string {
        if (! $paymentMethod->requiresPaymentProof()) {
            return null;
        }

        $path = $paymentProof?->store('payment-proofs', 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'The payment proof could not be stored. Please try again.',
            ]);
        }

        return $path;
    }

    /**
     * Remove evidence that no committed order references.
     */
    private function deletePaymentProof(?string $paymentProofPath): void
    {
        if ($paymentProofPath !== null) {
            Storage::disk('local')->delete($paymentProofPath);
        }
    }
}
