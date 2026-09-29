<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\BuildCartViewData;
use App\Actions\Cart\CartOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartItemController extends Controller
{
    public function store(
        StoreCartItemRequest $request,
        CartService $cartService,
        BuildCartViewData $buildCartViewData,
    ): CartResource {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $cartService->add($customer, $request->integer('product_id'), $request->integer('quantity'));
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return new CartResource($buildCartViewData->execute($customer));
    }

    public function update(
        UpdateCartItemRequest $request,
        int $cartItem,
        CartService $cartService,
        BuildCartViewData $buildCartViewData,
    ): CartResource {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $cartService->updateQuantity($customer, $cartItem, $request->integer('quantity'));
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return new CartResource($buildCartViewData->execute($customer));
    }

    public function destroy(
        Request $request,
        int $cartItem,
        CartService $cartService,
        BuildCartViewData $buildCartViewData,
    ): CartResource {
        /** @var User $customer */
        $customer = $request->user();

        $cartService->remove($customer, $cartItem);

        return new CartResource($buildCartViewData->execute($customer));
    }
}
