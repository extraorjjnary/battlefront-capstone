<?php

namespace App\Http\Controllers;

use App\Actions\Order\OrderPlacementException;
use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
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

        return to_route('orders.show', $order);
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
        ]);
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
            'reference' => '#'.$order->id,
            'created_at' => $order->created_at->toIso8601String(),
            'status' => [
                'value' => $order->status->value,
                'label' => $order->status->label(),
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
