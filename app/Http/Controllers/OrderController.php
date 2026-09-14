<?php

namespace App\Http\Controllers;

use App\Actions\Order\OrderPlacementException;
use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
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
            $placeOrder->execute($customer, [
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

        return to_route('cart.index');
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
