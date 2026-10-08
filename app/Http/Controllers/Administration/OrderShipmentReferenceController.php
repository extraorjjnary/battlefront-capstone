<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderShipmentReferenceRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderShipmentReferenceController extends Controller
{
    public function update(UpdateOrderShipmentReferenceRequest $request, Order $order, OrderProcessingService $processing): RedirectResponse
    {
        $processing->updateShipmentReference(
            $order,
            $request->validated('tracking_reference'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shipment reference updated.']);

        return back();
    }
}
