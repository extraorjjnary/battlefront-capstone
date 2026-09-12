<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CartOperationException;
use App\Actions\Cart\ManageCart;
use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CartItemController extends Controller
{
    /**
     * Add a product to the authenticated customer's cart.
     */
    public function store(StoreCartItemRequest $request, ManageCart $manageCart): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $manageCart->add(
                $customer,
                $request->integer('product_id'),
                $request->integer('quantity'),
            );
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product added to cart.'),
        ]);

        return back();
    }

    /**
     * Update a customer-owned cart item quantity.
     */
    public function update(
        UpdateCartItemRequest $request,
        int $cartItem,
        ManageCart $manageCart,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $manageCart->updateQuantity(
                $customer,
                $cartItem,
                $request->integer('quantity'),
            );
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cart quantity updated.'),
        ]);

        return back();
    }

    /**
     * Remove a customer-owned item from the cart.
     */
    public function destroy(Request $request, int $cartItem, ManageCart $manageCart): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        $manageCart->remove($customer, $cartItem);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product removed from cart.'),
        ]);

        return back();
    }
}
