<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Checkout\PrepareCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutPreviewRequest;
use App\Http\Resources\Api\V1\CheckoutResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function show(CheckoutPreviewRequest $request, PrepareCheckout $prepareCheckout): CheckoutResource
    {
        /** @var User $customer */
        $customer = $request->user();

        try {
            return new CheckoutResource($prepareCheckout->execute($customer, $request->cartItemIds()));
        } catch (CheckoutUnavailableException $exception) {
            throw ValidationException::withMessages(['cart' => $exception->getMessage()]);
        }
    }
}
