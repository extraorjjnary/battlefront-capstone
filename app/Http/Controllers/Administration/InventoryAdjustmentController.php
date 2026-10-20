<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\DecideInventoryAdjustment;
use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Actions\Inventory\WithdrawInventoryAdjustment;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $requests = DB::table('inventory_adjustment_requests as requests')
            ->join('products', 'products.id', '=', 'requests.product_id')
            ->join('users', 'users.id', '=', 'requests.requester_id')
            ->select('requests.id', 'requests.requester_id', 'requests.replaces_request_id', 'requests.observed_quantity', 'requests.target_quantity', 'requests.initial_reorder_level', 'requests.reason', 'requests.decision_reason', 'requests.status', 'requests.created_at', 'products.name as product_name', 'users.name as requester_name')
            ->when($case, fn ($query, $id) => $query->where('requests.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('requests.reason', 'like', '%'.$search.'%')))
            ->orderByRaw("CASE WHEN requests.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requests.created_at')
            ->paginate(20)->withQueryString();
        $replacementIds = DB::table('inventory_adjustment_requests')
            ->whereIn('replaces_request_id', $requests->getCollection()->pluck('id'))
            ->pluck('id', 'replaces_request_id');
        $requests->getCollection()->transform(function ($entry) use ($replacementIds) {
            $entry->replacement_id = $replacementIds->get($entry->id);

            return $entry;
        });

        return Inertia::render('Administration/InventoryAdjustments', [
            'requests' => $requests,
            'selected_case' => $case,
            'filters' => ['q' => $filters['q'] ?? null],
        ]);
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

    public function withdraw(Request $request, int $adjustment, WithdrawInventoryAdjustment $withdrawal): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $withdrawal->execute($adjustment, $request->user(), $validated['reason']);

        return back();
    }

    public function replace(Request $request, int $adjustment, RequestInventoryAdjustment $replacement): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);
        $previous = DB::table('inventory_adjustment_requests')->where('id', $adjustment)->first();
        if ($previous === null) {
            abort(404);
        }
        $product = Product::query()->whereKey($previous->product_id)->firstOrFail();
        $replacement->execute($product, $request->user(), $validated['quantity'], $validated['reason'], $validated['reorder_level'] ?? null, $adjustment);

        return back();
    }
}
