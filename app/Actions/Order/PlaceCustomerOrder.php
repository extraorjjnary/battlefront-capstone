<?php

namespace App\Actions\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderPlacementService;
use DomainException;
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
     * @param  array{cart_item_ids: list<int>, recipient_name: string, contact_number: string, fulfillment_method: string, delivery_address?: string|null, delivery_destination: string|null, payment_method: string}  $validated
     */
    public function execute(User $customer, array $validated, ?UploadedFile $paymentProof): Order
    {
        $paymentMethod = PaymentMethod::from($validated['payment_method']);
        $paymentProofPath = $this->storePaymentProof->execute(
            $paymentProof,
            $paymentMethod,
        );

        try {
            $checkout = [
                'cart_item_ids' => $validated['cart_item_ids'],
                'recipient_name' => $validated['recipient_name'],
                'contact_number' => $validated['contact_number'],
                'fulfillment_method' => FulfillmentMethod::from($validated['fulfillment_method']),
                'delivery_address' => $validated['delivery_address'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_proof_path' => $paymentProofPath,
            ];

            return $checkout['fulfillment_method'] === FulfillmentMethod::Delivery
                ? $this->orderPlacementService->executeWithDeliveryQuote($customer, $checkout, $validated['delivery_destination'] ?? '')
                : $this->orderPlacementService->execute($customer, $checkout);
        } catch (OrderPlacementException $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw ValidationException::withMessages([
                'cart' => $exception->getMessage(),
            ]);
        } catch (DomainException $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            if ($exception->getMessage() !== 'Unsupported delivery destination.') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'delivery_destination' => 'Select a supported delivery destination.',
            ]);
        } catch (Throwable $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw $exception;
        }
    }
}
