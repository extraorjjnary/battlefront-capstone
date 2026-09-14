<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Cart\ReviewCheckoutCart;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ValidateCheckoutController extends Controller
{
    /**
     * Validate checkout details without placing an order.
     */
    public function __invoke(
        ValidateCheckoutRequest $request,
        ReviewCheckoutCart $reviewCheckoutCart,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $reviewCheckoutCart->execute($customer);
        } catch (CheckoutUnavailableException $exception) {
            throw ValidationException::withMessages([
                'cart' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Checkout details are valid. Your order has not been placed yet.',
        ]);

        return back();
    }
}
