<?php

namespace App\Http\Controllers;

use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderCancellationRequestController extends Controller
{
    public function __invoke(Request $request, int $order, OrderProcessingService $processing): RedirectResponse
    {
        $validated = $request->validate(['customer_note' => ['nullable', 'string', 'max:1000']]);
        $processing->requestCancellation($request->user(), $order, $validated['customer_note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancellation requested. Order handling is paused for review.']);

        return to_route('orders.show', $order);
    }
}
