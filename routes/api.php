<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CustomerReturnRequestController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderCancellationController;
use App\Http\Controllers\Api\V1\OrderCancellationRequestController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentProofController;
use App\Http\Controllers\Api\V1\OrderPaymentRetryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\CustomerRefundProofController;
use App\Http\Controllers\DeliveryLocationController;
use App\Http\Controllers\DeliveryQuoteController;
use App\Http\Controllers\PayMongoWebhookController;
use App\Http\Controllers\RecommendationInteractionController;
use App\Http\Middleware\AuthorizeApiChatbot;
use App\Http\Middleware\AuthorizeApiRecommendations;
use Illuminate\Support\Facades\Route;

Route::post('paymongo/webhook', PayMongoWebhookController::class)->name('paymongo.webhook');

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
            Route::get('recommendations', [RecommendationController::class, 'feed'])->name('recommendations.feed');
            Route::post('recommendations/interactions', RecommendationInteractionController::class)
                ->name('recommendations.interactions.store');
        });

        // chatbot
        Route::post('chatbot', [ChatbotController::class, 'store'])
            ->middleware([AuthorizeApiChatbot::class, 'throttle:chatbot'])->name('chatbot.store');

        // auth customers endpoint
        Route::middleware(['auth:sanctum', 'can:use-customer-cart'])->group(function (): void {
            Route::get('orders/{order}/refund-payments/{payment}/proof/{evidence}', CustomerRefundProofController::class)
                ->whereNumber(['order', 'payment', 'evidence'])->name('orders.refund-payments.proof.show');
            Route::get('orders/{order}/return-requests', [CustomerReturnRequestController::class, 'index'])
                ->whereNumber('order')->name('orders.return-requests.index');
            Route::post('orders/{order}/return-requests', [CustomerReturnRequestController::class, 'store'])
                ->whereNumber('order')->name('orders.return-requests.store');
            Route::get('orders/{order}/return-requests/{returnRequest}', [CustomerReturnRequestController::class, 'show'])
                ->whereNumber(['order', 'returnRequest'])->name('orders.return-requests.show');
            Route::post('orders/{order}/return-requests/{returnRequest}/withdraw', [CustomerReturnRequestController::class, 'withdraw'])
                ->whereNumber(['order', 'returnRequest'])->name('orders.return-requests.withdraw');
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            // personalized recommendations
            Route::get('recommendations/personalized', [RecommendationController::class, 'personalized'])
                ->name('recommendations.personalized');

            // notifications and push devices
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
            Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::patch('notifications/{notification}/read', [NotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');
            Route::get('push-devices/{device}', [PushDeviceController::class, 'show'])->whereUuid('device')->name('push-devices.show');
            Route::put('push-devices/{device}', [PushDeviceController::class, 'update'])->whereUuid('device')->name('push-devices.update');
            Route::delete('push-devices/{device}', [PushDeviceController::class, 'destroy'])->whereUuid('device')->name('push-devices.destroy');

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
            Route::get('delivery-locations', [DeliveryLocationController::class, 'index'])->name('delivery-locations.index');
            Route::get('checkout/delivery-quote', [DeliveryQuoteController::class, 'show'])->name('checkout.delivery-quote.show');

            // orders
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::post('orders/{order}/cancellation-requests', OrderCancellationRequestController::class)
                ->whereNumber('order')->name('orders.cancellation-requests.store');
            Route::post('orders/{order}/cancel', OrderCancellationController::class)
                ->whereNumber('order')->name('orders.cancel.store');
            Route::post('orders/{order}/paymongo/retry', OrderPaymentRetryController::class)
                ->middleware('throttle:10,1')
                ->whereNumber('order')->name('orders.paymongo.retry');
            Route::get('orders/{order}', [OrderController::class, 'show'])
                ->whereNumber('order')->name('orders.show');
            Route::post('orders/{order}/payment-proof', OrderPaymentProofController::class)
                ->whereNumber('order')->name('orders.payment-proof.store');

        });
    });
