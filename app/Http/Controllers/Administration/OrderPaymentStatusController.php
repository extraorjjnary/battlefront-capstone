<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Order\ProcessOrder;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderPaymentStatusRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderPaymentStatusController extends Controller
{
    /**
     * Record a final manual payment decision.
     */
    public function update(
        UpdateOrderPaymentStatusRequest $request,
        Order $order,
        ProcessOrder $processOrder,
    ): RedirectResponse {
        $processOrder->updatePaymentStatus(
            $order,
            PaymentStatus::from($request->validated('payment_status')),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Payment status updated.',
        ]);

        return back();
    }
}
