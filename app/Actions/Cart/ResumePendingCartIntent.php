<?php

namespace App\Actions\Cart;

use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ResumePendingCartIntent
{
    public function __construct(private CartService $cartService) {}

    public function execute(Request $request): ?RedirectResponse
    {
        $intent = $request->session()->pull('pending_cart_intent');

        if (! is_array($intent)
            || ! is_int($intent['product_id'] ?? null) || $intent['product_id'] <= 0
            || ! is_int($intent['quantity'] ?? null) || $intent['quantity'] <= 0
            || ! is_int($intent['expires_at'] ?? null) || $intent['expires_at'] <= now()->getTimestamp()
            || ! is_string($intent['token'] ?? null) || ! Str::isUuid($intent['token'])) {
            return null;
        }

        if (! Cache::add('pending-cart-intent:'.$intent['token'], true, max(1, $intent['expires_at'] - now()->getTimestamp()))) {
            return null;
        }

        /** @var User $customer */
        $customer = $request->user();

        if (! $customer->can('use-customer-cart')) {
            return null;
        }

        $request->session()->forget('url.intended');

        try {
            $this->cartService->add($customer, $intent['product_id'], $intent['quantity']);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Product added to cart.'),
            ]);
        } catch (CartOperationException|ModelNotFoundException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception instanceof CartOperationException
                    ? $exception->getMessage()
                    : __('This product is no longer available.'),
            ]);
        }

        return to_route('cart.index');
    }
}
