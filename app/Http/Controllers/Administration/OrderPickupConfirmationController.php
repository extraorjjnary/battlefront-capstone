<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ConfirmOrderPickupRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderPickupConfirmationController extends Controller
{
    public function store(ConfirmOrderPickupRequest $request, Order $order, OrderProcessingService $processing): RedirectResponse
    {
        $processing->confirmPickup($order, $request->boolean('payment_received_confirmed'), $request->boolean('products_handed_over_confirmed'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Payment and product handover confirmed. Pickup completed and sale recorded.',
        ]);

        return back();
    }
}
