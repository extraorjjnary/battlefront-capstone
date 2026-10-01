<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentProofController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Middleware\AuthorizeApiChatbot;
use App\Http\Middleware\AuthorizeApiRecommendations;
use Illuminate\Support\Facades\Route;

// Version 1 endpoints: (note: mobile is customer-centric only it's exclude administration responsibility/operations.)
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('throttle:api-v1')
    ->group(function (): void {
        Route::get('health', fn () => response()->json([
            'data' => ['status' => 'ok'],
        ]))->name('health');

        // authentication
        Route::post('auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:5,1')->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')->name('auth.login');

        // products
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/filters', [ProductController::class, 'filters'])->name('products.filters');
        Route::get('products/{product}', [ProductController::class, 'show'])
            ->whereNumber('product')->name('products.show');

        // branch
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

        // recommendations
        Route::middleware(AuthorizeApiRecommendations::class)->group(function (): void {
            Route::get('recommendations/options', [RecommendationController::class, 'options'])->name('recommendations.options');
            Route::post('recommendations', [RecommendationController::class, 'results'])->name('recommendations.results');
        });

        // chatbot
        Route::post('chatbot', [ChatbotController::class, 'store'])
            ->middleware([AuthorizeApiChatbot::class, 'throttle:chatbot'])->name('chatbot.store');

        // auth customers endpoint
        Route::middleware(['auth:sanctum', 'can:use-customer-cart'])->group(function (): void {
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            // profile
            Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

            // cart
            Route::get('cart', [CartController::class, 'index'])->name('cart.index');

            // cart items
            Route::post('cart/items', [CartItemController::class, 'store'])->name('cart.items.store');
            Route::patch('cart/items/{cartItem}', [CartItemController::class, 'update'])
                ->whereNumber('cartItem')->name('cart.items.update');
            Route::delete('cart/items/{cartItem}', [CartItemController::class, 'destroy'])
                ->whereNumber('cartItem')->name('cart.items.destroy');

            // checkout preview
            Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');

            // orders
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::get('orders/{order}', [OrderController::class, 'show'])
                ->whereNumber('order')->name('orders.show');
            Route::post('orders/{order}/payment-proof', OrderPaymentProofController::class)
                ->whereNumber('order')->name('orders.payment-proof.store');

        });
    });
