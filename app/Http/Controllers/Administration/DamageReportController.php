<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleDamage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DamageReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $overdueCutoff = now()->startOfDay()->subDays(3);
        $reports = DB::table('damage_reports as reports')
            ->join('products', 'products.id', '=', 'reports.product_id')
            ->join('users', 'users.id', '=', 'reports.requester_id')
            ->select('reports.*', 'products.name as product_name', 'users.name as requester_name')
            ->when($case, fn ($query, $id) => $query->where('reports.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('reports.reason', 'like', '%'.$search.'%')))
            ->when($request->boolean('overdue'), fn ($query) => $query->where('reports.status', 'pending')
                ->where('reports.created_at', '<', $overdueCutoff))
            ->orderByDesc('reports.created_at')->paginate(20)->withQueryString();
        $heldBySupplierReturn = DB::table('supplier_returns')->whereIn('damage_report_id', $reports->getCollection()->pluck('id'))
            ->whereIn('status', ['pending', 'approved', 'rejected_follow_up'])->pluck('damage_report_id')->flip();
        $reports->getCollection()->transform(function ($report) use ($heldBySupplierReturn, $overdueCutoff) {
            $report->held_quantity = $report->status === 'pending' || ($report->disposition === 'supplier_return' && $heldBySupplierReturn->has($report->id))
                ? $report->quantity : 0;
            $report->age_days = (int) now()->startOfDay()->diffInDays($report->created_at, true);
            $report->inspection_overdue = $report->status === 'pending'
                && Carbon::parse($report->created_at)->lt($overdueCutoff);

            return $report;
        });

        return Inertia::render('Administration/DamageReports', [
            'reports' => $reports,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $request->boolean('overdue')],
            'selected_case' => $case,
            'evidence' => DB::table('administration_evidence')->where('subject_type', 'damage_report')
                ->whereIn('subject_id', $reports->getCollection()->pluck('id'))
                ->get(['id', 'subject_id', 'original_name'])->groupBy('subject_id'),
            'products' => Product::query()->whereHas('inventory', fn ($query) => $query->where('quantity', '>', 0))
                ->with('inventory:id,product_id,quantity')->orderBy('name')->get(['id', 'product_code', 'name'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code, 'available_quantity' => $product->inventory->quantity]),
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, HandleDamage $damage): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(['handling_damage', 'storage_damage', 'packing_damage', 'defect_discovered', 'other'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $damage->report(Product::query()->whereKey($validated['product_id'])->firstOrFail(), $request->user(), $validated['quantity'], $validated['reason'], $validated['reason_code']);

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
