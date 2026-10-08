<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Checkout\PrepareCheckout;
use App\Http\Requests\CheckoutPreviewRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    /**
     * Display checkout for the authenticated customer's ready cart.
     */
    public function index(
        CheckoutPreviewRequest $request,
        PrepareCheckout $prepareCheckout,
    ): Response|RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $checkout = $prepareCheckout->execute($customer, $request->cartItemIds());
        } catch (CheckoutUnavailableException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return to_route('cart.index');
        }

        return Inertia::render('Checkout/Index', $checkout);
    }
}
