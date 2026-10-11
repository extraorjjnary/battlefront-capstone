<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CartOperationException;
use App\Http\Requests\StorePendingCartIntentRequest;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingCartIntentController extends Controller
{
    public function store(StorePendingCartIntentRequest $request, CartService $cartService): RedirectResponse
    {
        $product = Product::query()->with(['category', 'inventory'])->findOrFail($request->integer('product_id'));

        try {
            $cartService->ensureAvailableForCheckout(
                $product,
                $product->category,
                $product->inventory,
                $request->integer('quantity'),
            );
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        $request->session()->put('pending_cart_intent', [
            'product_id' => $product->id,
            'quantity' => $request->integer('quantity'),
            'expires_at' => now()->addMinutes(15)->getTimestamp(),
            'token' => (string) Str::uuid(),
        ]);

        return to_route('login');
    }
}
