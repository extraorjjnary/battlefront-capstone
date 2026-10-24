<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrderCancellationRequestController extends Controller
{
    public function update(Request $request, Order $order, OrderProcessingService $processing): RedirectResponse
    {
        abort_unless($processing->canReviewCancellation($request->user()), 403);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'amount_received' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'decision_note' => ['required', 'string', 'min:5', 'max:2000'],
            'account_checked' => ['required', 'accepted'],
        ]);
        $processing->reviewCancellation($order, $request->user(), $validated['decision'] === 'approve', (string) $validated['amount_received'], $validated['decision_note'], $request->boolean('account_checked'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['decision'] === 'approve' ? 'Cancellation approved. Any refund owed must be transferred manually.' : 'Cancellation rejected. Normal order handling can resume.']);

        return back();
    }
}
