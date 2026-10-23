<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleSupplierReturn;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryCaseReviewService;
use App\Support\OperationalRequestReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierReturnController extends Controller
{
    public function index(Request $request, InventoryCaseReviewService $review): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;

        return Inertia::render('Administration/SupplierReturns', $review->supplierReturns($filters, $case, $request->boolean('overdue')));
    }

    public function store(Request $request, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(['defective', 'wrong_item', 'supplier_damage', 'other'])],
            'reason' => ['required_if:reason_code,other', 'nullable', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->request($request->user(), $validated['supplier_id'], $validated['product_id'], $validated['quantity'], OperationalRequestReason::resolve($validated['reason_code'], $validated['reason'] ?? null), $validated['reason_code']);

        return back();
    }

    public function decide(Request $request, int $supplierReturn, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->decide($supplierReturn, $request->user(), $validated['decision'] === 'approve', $validated['reason'] ?? null);

        return back();
    }

    public function dispatch(Request $request, int $supplierReturn, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate(['external_reference' => ['required', 'string', 'max:255']]);
        $returns->dispatch($supplierReturn, $request->user(), $validated['external_reference']);

        return back();
    }

    public function acknowledge(Request $request, int $supplierReturn, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate(['external_reference' => ['required', 'string', 'max:255']]);
        $returns->acknowledge($supplierReturn, $request->user(), $validated['external_reference']);

        return back();
    }

    public function followUp(Request $request, int $supplierReturn, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['retry', 'release', 'write_off'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'supplier_id' => ['required_if:decision,retry', 'prohibited_unless:decision,retry', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'inspection_confirmed' => ['sometimes', 'required_if:decision,release', 'accepted'],
        ]);
        $returns->followUp($supplierReturn, $request->user(), $validated['decision'], $validated['reason'], $validated['supplier_id'] ?? null, isset($validated['inspection_confirmed']));

        return back();
    }

    public function resolve(Request $request, int $supplierReturn, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['replacement', 'credit', 'refund', 'rejected'])],
            'external_reference' => ['required', 'string', 'max:255'],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->resolve($supplierReturn, $request->user(), $validated['outcome'], $validated['external_reference'], $validated['notes']);

        return back();
    }
}
