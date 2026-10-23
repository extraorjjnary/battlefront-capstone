<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Http\Requests\StoreCustomerReturnRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerReturnRequestController extends Controller
{
    public function store(StoreCustomerReturnRequest $request, int $order, HandleCustomerReturnRequest $returns): RedirectResponse
    {
        $returns->submit($request->user(), $order, $request->returnRequestData());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Return request submitted. Awaiting item receipt.']);

        return to_route('orders.show', $order);
    }

    public function withdraw(Request $request, int $order, int $returnRequest, HandleCustomerReturnRequest $returns): RedirectResponse
    {
        $returns->withdraw($request->user(), $order, $returnRequest);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Return request withdrawn.']);

        return to_route('orders.show', $order);
    }
}
