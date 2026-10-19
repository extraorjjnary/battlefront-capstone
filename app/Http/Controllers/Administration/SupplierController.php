<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        return Inertia::render('Administration/Suppliers', [
            'suppliers' => DB::table('suppliers')
                ->when($filters['q'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
                ->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $validated): void {
            $id = DB::table('suppliers')->insertGetId([...$validated, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'supplier.created',
                'subject_type' => 'supplier',
                'subject_id' => $id,
                'changes' => json_encode($validated, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supplier added.']);

        return back();
    }

    public function update(Request $request, int $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($supplier)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'edit_version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($request, $supplier, $validated): void {
            $old = DB::table('suppliers')->where('id', $supplier)->lockForUpdate()->first();
            if ($old === null) {
                abort(404);
            }
            if ((int) $validated['edit_version'] !== (int) $old->edit_version) {
                throw ValidationException::withMessages(['edit_version' => 'This supplier changed while you were editing. Reload and review the latest values before saving.']);
            }
            unset($validated['edit_version']);
            DB::table('suppliers')->where('id', $supplier)->update([...$validated, 'edit_version' => $old->edit_version + 1, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'supplier.updated',
                'subject_type' => 'supplier',
                'subject_id' => $supplier,
                'changes' => json_encode(['before' => $old, 'after' => $validated], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supplier updated.']);

        return back();
    }
}
