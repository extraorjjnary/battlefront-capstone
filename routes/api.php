<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('throttle:api-v1')
    ->group(function (): void {
        Route::get('health', fn () => response()->json([
            'data' => ['status' => 'ok'],
        ]))->name('health');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/filters', [ProductController::class, 'filters'])->name('products.filters');
        Route::get('products/{product}', [ProductController::class, 'show'])
            ->whereNumber('product')->name('products.show');
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

        Route::post('auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:5,1')->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')->name('auth.login');

        Route::middleware(['auth:sanctum', 'can:use-customer-cart'])->group(function (): void {
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

            Route::get('cart', [CartController::class, 'show'])->name('cart.show');
            Route::post('cart/items', [CartItemController::class, 'store'])->name('cart.items.store');
            Route::patch('cart/items/{cartItem}', [CartItemController::class, 'update'])
                ->whereNumber('cartItem')->name('cart.items.update');
            Route::delete('cart/items/{cartItem}', [CartItemController::class, 'destroy'])
                ->whereNumber('cartItem')->name('cart.items.destroy');
        });
    });
