<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateOrderShipmentOperationsRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderShipmentOperationsController extends Controller
{
    public function update(
        UpdateOrderShipmentOperationsRequest $request,
        Order $order,
        OrderProcessingService $processing,
    ): RedirectResponse {
        $processing->updateShipmentOperations($order, $request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Shipment operations updated.',
        ]);

        return back();
    }
}
