<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Order\ProcessOrder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderStatusRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderStatusController extends Controller
{
    /**
     * Update an order through an approved processing transition.
     */
    public function update(
        UpdateOrderStatusRequest $request,
        Order $order,
        ProcessOrder $processOrder,
    ): RedirectResponse {
        $processOrder->updateStatus(
            $order,
            OrderStatus::from($request->validated('status')),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Order status updated.',
        ]);

        return back();
    }
}
