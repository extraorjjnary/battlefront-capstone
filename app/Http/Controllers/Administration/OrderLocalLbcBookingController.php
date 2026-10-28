<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SimulateLocalLbcBookingRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderLocalLbcBookingController extends Controller
{
    public function store(
        SimulateLocalLbcBookingRequest $request,
        Order $order,
        OrderProcessingService $processing,
    ): RedirectResponse {
        $processing->simulateLocalLbcBooking($order, actor: $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Local simulation recorded. No booking was sent to LBC.',
        ]);

        return back();
    }
}
