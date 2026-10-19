<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleSupplierReturn;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $returns = DB::table('supplier_returns as returns')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('suppliers', 'suppliers.id', '=', 'returns.supplier_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->leftJoin('supplier_return_resolutions as resolutions', 'resolutions.supplier_return_id', '=', 'returns.id')
            ->select('returns.*', 'products.name as product_name', 'suppliers.name as supplier_name', 'users.name as requester_name', 'resolutions.outcome as resolution_outcome', 'resolutions.external_reference as resolution_reference', 'resolutions.notes as resolution_notes')
            ->when($case, fn ($query, $id) => $query->where('returns.id', $id))
            ->orderByDesc('returns.created_at')->paginate(20)->withQueryString();
        $returns->getCollection()->transform(function ($return) {
            $return->held_quantity = in_array($return->status, ['pending', 'approved', 'rejected_follow_up'], true) ? $return->quantity : 0;

            return $return;
        });

        return Inertia::render('Administration/SupplierReturns', [
            'returns' => $returns,
            'selected_case' => $case,
            'products' => Product::query()->whereHas('inventory', fn ($query) => $query->where('quantity', '>', 0))->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->request($request->user(), $validated['supplier_id'], $validated['product_id'], $validated['quantity'], $validated['reason']);

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
        ]);
        $returns->followUp($supplierReturn, $request->user(), $validated['decision'], $validated['reason'], $validated['supplier_id'] ?? null);

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
