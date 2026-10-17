<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\DecideInventoryAdjustment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryAdjustmentController extends Controller
{
    public function index(): Response
    {
        $requests = DB::table('inventory_adjustment_requests as requests')
            ->join('products', 'products.id', '=', 'requests.product_id')
            ->join('users', 'users.id', '=', 'requests.requester_id')
            ->select('requests.id', 'requests.requester_id', 'requests.observed_quantity', 'requests.target_quantity', 'requests.reason', 'requests.status', 'requests.created_at', 'products.name as product_name', 'users.name as requester_name')
            ->orderByRaw("CASE WHEN requests.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requests.created_at')
            ->paginate(20);

        return Inertia::render('Administration/InventoryAdjustments', ['requests' => $requests]);
    }

    public function decide(Request $request, int $adjustment, DecideInventoryAdjustment $decision): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $decision->execute($adjustment, $request->user(), $validated['decision'] === 'approve', $validated['reason']);

        return back();
    }
}
