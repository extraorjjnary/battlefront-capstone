<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleDamage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryCaseReviewService;
use App\Support\OperationalRequestReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DamageReportController extends Controller
{
    public function index(Request $request, InventoryCaseReviewService $review): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;

        return Inertia::render('Administration/DamageReports', $review->damageReports($filters, $case, $request->boolean('overdue')));
    }

    public function store(Request $request, HandleDamage $damage): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(['handling_damage', 'storage_damage', 'packing_damage', 'defect_discovered', 'other'])],
            'reason' => ['required_if:reason_code,other', 'nullable', 'string', 'min:5', 'max:2000'],
        ]);
        $damage->report(Product::query()->whereKey($validated['product_id'])->firstOrFail(), $request->user(), $validated['quantity'], OperationalRequestReason::resolve($validated['reason_code'], $validated['reason'] ?? null), $validated['reason_code']);

        return back();
    }

    public function decide(Request $request, int $damageReport, HandleDamage $damage): RedirectResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in(['release', 'write_off', 'supplier_return'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'supplier_id' => ['required_if:disposition,supplier_return', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'inspection_confirmed' => ['sometimes', 'required_if:disposition,release', 'accepted'],
        ]);
        $damage->decide($damageReport, $request->user(), $validated['disposition'], $validated['reason'], $validated['supplier_id'] ?? null, isset($validated['inspection_confirmed']));

        return back();
    }
}
