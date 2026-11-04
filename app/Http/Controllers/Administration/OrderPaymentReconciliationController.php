<?php

namespace App\Http\Controllers\Administration;

use App\Actions\PayMongo\RequestPaymentReconciliation;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderPaymentReconciliationController extends Controller
{
    public function __invoke(Request $request, Order $order, RequestPaymentReconciliation $reconciliation): RedirectResponse
    {
        $reconciliation->execute($request->user(), $order);

        return back()->with('success', 'Payment check requested. This does not create a payment or confirm payment manually.');
    }
}
