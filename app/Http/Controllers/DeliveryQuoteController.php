<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Checkout\PrepareCheckout;
use App\Http\Requests\CheckoutPreviewRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

class DeliveryQuoteController extends Controller
{
    public function show(CheckoutPreviewRequest $request, PrepareCheckout $checkout): JsonResource
    {
        $input = $request->validate(['delivery_city_code' => ['required', 'string', 'regex:/\A[0-9]{10}\z/']]);
        try {
            return JsonResource::make($checkout->quote($request->user(), $request->cartItemIds(), $input['delivery_city_code']));
        } catch (CheckoutUnavailableException $exception) {
            throw ValidationException::withMessages(['cart' => $exception->getMessage()]);
        }
    }
}
