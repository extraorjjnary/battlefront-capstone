<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleDamage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DamageReportController extends Controller
{
    public function index(): Response
    {
        $reports = DB::table('damage_reports as reports')
            ->join('products', 'products.id', '=', 'reports.product_id')
            ->join('users', 'users.id', '=', 'reports.requester_id')
            ->select('reports.*', 'products.name as product_name', 'users.name as requester_name')
            ->orderByDesc('reports.created_at')->paginate(20);

        return Inertia::render('Administration/DamageReports', [
            'reports' => $reports,
            'products' => Product::query()->whereHas('inventory', fn ($query) => $query->where('quantity', '>', 0))
                ->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, HandleDamage $damage): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $damage->report(Product::query()->whereKey($validated['product_id'])->firstOrFail(), $request->user(), $validated['quantity'], $validated['reason']);

        return back();
    }

    public function decide(Request $request, int $damageReport, HandleDamage $damage): RedirectResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in(['release', 'write_off', 'supplier_return'])],
            'supplier_id' => ['required_if:disposition,supplier_return', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
        ]);
        $damage->decide($damageReport, $request->user(), $validated['disposition'], $validated['supplier_id'] ?? null);

        return back();
    }
}
