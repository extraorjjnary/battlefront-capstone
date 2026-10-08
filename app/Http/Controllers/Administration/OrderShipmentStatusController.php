<?php

namespace App\Http\Controllers\Administration;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderShipmentStatusRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderShipmentStatusController extends Controller
{
    public function update(UpdateOrderShipmentStatusRequest $request, Order $order, OrderProcessingService $processing): RedirectResponse
    {
        $processing->updateShipmentStatus(
            $order,
            ShipmentStatus::from($request->validated('status')),
            $request->validated('tracking_reference'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shipment status updated.']);

        return back();
    }
}
