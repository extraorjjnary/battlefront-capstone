<?php

namespace App\Http\Controllers\Administration;

use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderPaymentStatusRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
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
        OrderProcessingService $orderProcessingService,
    ): RedirectResponse {
        $orderProcessingService->updatePaymentStatus(
            $order,
            PaymentStatus::from($request->validated('payment_status')),
            $request->validated('rejection_reason') !== null
                ? PaymentRejectionReason::from($request->validated('rejection_reason'))
                : null,
            $request->validated('rejection_note'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Payment status updated.',
        ]);

        return back();
    }
}
