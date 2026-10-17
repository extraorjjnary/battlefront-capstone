<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Order\AssignOrderHandler;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderHandlerController extends Controller
{
    public function claim(Request $request, Order $order, AssignOrderHandler $handler): RedirectResponse
    {
        $handler->execute($order, $request->user(), $request->user()->id, claimOnly: true);

        return back();
    }

    public function assign(Request $request, Order $order, AssignOrderHandler $handler): RedirectResponse
    {
        $validated = $request->validate(['handler_id' => ['required', 'integer', 'exists:users,id']]);
        $handler->execute($order, $request->user(), $validated['handler_id']);

        return back();
    }
}
