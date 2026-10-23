<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(Request $request, int $order, RetryDirectOnlineBanking $retry): Response
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            return Inertia::location($retry->execute($persistedOrder));
        } catch (RuntimeException) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'The bank payment is being reconciled. Check this order again shortly.']);

            return to_route('orders.show', $persistedOrder);
        }
    }
}
