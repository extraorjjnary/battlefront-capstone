<?php

namespace App\Http\Controllers;

use App\Actions\Order\ResubmitPaymentProof;
use App\Http\Requests\StoreOrderPaymentProofRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class OrderPaymentProofController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        StoreOrderPaymentProofRequest $request,
        int $order,
        ResubmitPaymentProof $resubmitPaymentProof,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();
        $paymentProofPath = $request->file('payment_proof')?->store('payment-proofs', 'local');

        if (! is_string($paymentProofPath)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'The replacement proof could not be stored. Please try again.',
            ]);
        }

        try {
            $resubmitPaymentProof->execute($customer, $persistedOrder, $paymentProofPath);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paymentProofPath);

            throw $exception;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Replacement payment proof submitted for review.',
        ]);

        return back();
    }
}
