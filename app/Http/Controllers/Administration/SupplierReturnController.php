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
    public function index(): Response
    {
        $returns = DB::table('supplier_returns as returns')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('suppliers', 'suppliers.id', '=', 'returns.supplier_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->select('returns.*', 'products.name as product_name', 'suppliers.name as supplier_name', 'users.name as requester_name')
            ->orderByDesc('returns.created_at')->paginate(20);

        return Inertia::render('Administration/SupplierReturns', [
            'returns' => $returns,
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
        $validated = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $returns->decide($supplierReturn, $request->user(), $validated['decision'] === 'approve');

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
}
