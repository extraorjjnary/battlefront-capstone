<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleSupplierReturn;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Carbon\CarbonImmutable;
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
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $overdueCutoff = now()->startOfDay()->subDays(30);
        $returns = DB::table('supplier_returns as returns')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('suppliers', 'suppliers.id', '=', 'returns.supplier_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->leftJoin('supplier_return_resolutions as resolutions', 'resolutions.supplier_return_id', '=', 'returns.id')
            ->select('returns.*', 'products.name as product_name', 'suppliers.name as supplier_name', 'users.name as requester_name', 'resolutions.outcome as resolution_outcome', 'resolutions.external_reference as resolution_reference', 'resolutions.notes as resolution_notes')
            ->when($case, fn ($query, $id) => $query->where('returns.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('suppliers.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('returns.reason', 'like', '%'.$search.'%')
                ->orWhere('returns.external_reference', 'like', '%'.$search.'%')
                ->orWhere('returns.dispatch_reference', 'like', '%'.$search.'%')
                ->orWhere('returns.acknowledgement_reference', 'like', '%'.$search.'%')
                ->orWhere('resolutions.external_reference', 'like', '%'.$search.'%')))
            ->when($request->boolean('overdue'), fn ($query) => $query->where(function ($query): void {
                $query->whereIn('returns.status', ['pending', 'approved', 'dispatched', 'rejected_follow_up'])
                    ->orWhere(fn ($query) => $query->where('returns.status', 'closed')->whereNull('resolutions.supplier_return_id'));
            })->where('returns.created_at', '<', $overdueCutoff))
            ->orderByDesc('returns.created_at')->paginate(20)->withQueryString();
        $returns->getCollection()->transform(function ($return) use ($overdueCutoff) {
            $return->held_quantity = in_array($return->status, ['pending', 'approved', 'rejected_follow_up'], true) ? $return->quantity : 0;
            $return->age_days = (int) now()->startOfDay()->diffInDays($return->created_at, true);
            $isOpen = in_array($return->status, ['pending', 'approved', 'dispatched', 'rejected_follow_up'], true)
                || ($return->status === 'closed' && $return->resolution_outcome === null);
            $return->follow_up_overdue = $isOpen && CarbonImmutable::parse($return->created_at)->lt($overdueCutoff);

            return $return;
        });

        return Inertia::render('Administration/SupplierReturns', [
            'returns' => $returns,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $request->boolean('overdue')],
            'selected_case' => $case,
            'products' => Product::query()->whereHas('inventory', fn ($query) => $query->where('quantity', '>', 0))
                ->with('inventory:id,product_id,quantity')->orderBy('name')->get(['id', 'product_code', 'name'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code, 'available_quantity' => $product->inventory->quantity]),
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, HandleSupplierReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(['defective', 'wrong_item', 'supplier_damage', 'other'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->request($request->user(), $validated['supplier_id'], $validated['product_id'], $validated['quantity'], $validated['reason'], $validated['reason_code']);

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
