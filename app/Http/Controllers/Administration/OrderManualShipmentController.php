<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CreateManualShipmentRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderManualShipmentController extends Controller
{
    public function store(
        CreateManualShipmentRequest $request,
        Order $order,
        OrderProcessingService $processing,
    ): RedirectResponse {
        $data = $request->validated();
        $processing->createManualShipment(
            $order,
            $data['carrier'],
            $data['tracking_reference'] ?? null,
            isset($data['eta_min_days']) ? (int) $data['eta_min_days'] : null,
            isset($data['eta_max_days']) ? (int) $data['eta_max_days'] : null,
            $request->boolean('arrangement_confirmed'),
            actor: $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Manual LBC shipment recorded. The order amount remains unchanged.',
        ]);

        return back();
    }
}
