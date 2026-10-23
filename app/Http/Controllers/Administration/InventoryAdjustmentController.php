<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\DecideInventoryAdjustment;
use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Actions\Inventory\WithdrawInventoryAdjustment;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Repositories\Inventory\InventoryRepository;
use App\Support\OperationalRequestReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request, InventoryRepository $inventory): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $requests = $inventory->paginateAdjustments($filters, $case);

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
            'reason_code' => ['required', Rule::in(['damage', 'theft_loss', 'found_stock', 'count_error', 'receiving_error', 'unit_of_measure_error', 'other'])],
            'reason' => ['nullable', 'string', 'min:5', 'max:2000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);
        $previous = DB::table('inventory_adjustment_requests')->where('id', $adjustment)->first();
        if ($previous === null) {
            abort(404);
        }
        $product = Product::query()->whereKey($previous->product_id)->firstOrFail();
        $replacement->execute($product, $request->user(), $validated['quantity'], OperationalRequestReason::resolve($validated['reason_code'], $validated['reason'] ?? null, openingStock: $previous->initial_reorder_level !== null, notesOptional: true), $validated['reorder_level'] ?? null, $adjustment, reasonCode: $validated['reason_code']);

        return back();
    }
}
