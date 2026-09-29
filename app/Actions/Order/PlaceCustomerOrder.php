<?php

namespace App\Actions\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderPlacementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlaceCustomerOrder
{
    public function __construct(
        private readonly OrderPlacementService $orderPlacementService,
        private readonly StorePaymentProof $storePaymentProof,
        private readonly DeletePaymentProof $deletePaymentProof,
    ) {}

    /**
     * @param  array{recipient_name: string, contact_number: string, fulfillment_method: string, delivery_address?: string|null, payment_method: string}  $validated
     */
    public function execute(User $customer, array $validated, ?UploadedFile $paymentProof): Order
    {
        $paymentMethod = PaymentMethod::from($validated['payment_method']);
        $paymentProofPath = $this->storePaymentProof->execute(
            $paymentProof,
            $paymentMethod,
        );

        try {
            return $this->orderPlacementService->execute($customer, [
                'recipient_name' => $validated['recipient_name'],
                'contact_number' => $validated['contact_number'],
                'fulfillment_method' => FulfillmentMethod::from($validated['fulfillment_method']),
                'delivery_address' => $validated['delivery_address'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_proof_path' => $paymentProofPath,
            ]);
        } catch (OrderPlacementException $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw ValidationException::withMessages([
                'cart' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw $exception;
        }
    }
}
