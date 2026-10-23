<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ConfirmOrderCodCollectionRequest;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OrderCodCollectionController extends Controller
{
    public function store(ConfirmOrderCodCollectionRequest $request, Order $order, OrderProcessingService $processing): RedirectResponse
    {
        $processing->confirmCodCollection($order, $request->boolean('cod_collection_confirmed'), actor: $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'COD collection confirmed. Order completed and sale recorded.',
        ]);

        return back();
    }
}
