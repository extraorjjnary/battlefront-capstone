<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderCancellationController extends Controller
{
    public function __invoke(Request $request, int $order, OrderProcessingService $processing): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $processing->cancelForCustomer($customer, $order);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Order cancelled successfully.']);

        return to_route('orders.show', $order);
    }
}
