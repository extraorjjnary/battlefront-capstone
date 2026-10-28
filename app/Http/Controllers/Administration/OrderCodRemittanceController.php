<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ConfirmOrderCodRemittanceRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderCodRemittanceController extends Controller
{
    public function store(
        ConfirmOrderCodRemittanceRequest $request,
        Order $order,
        OrderProcessingService $processing,
    ): RedirectResponse {
        $processing->confirmCodRemittance(
            $order,
            $request->boolean('cod_remittance_confirmed'),
            $request->string('carrier_cod_remittance_reference')->toString(),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'LBC COD remittance reconciled. Order completed and sale recorded.',
        ]);

        return back();
    }
}
