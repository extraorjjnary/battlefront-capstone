<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Administration/Suppliers', [
            'suppliers' => DB::table('suppliers')->orderBy('name')->paginate(25),
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
        ]);
        DB::transaction(function () use ($request, $supplier, $validated): void {
            $old = DB::table('suppliers')->where('id', $supplier)->lockForUpdate()->first();
            if ($old === null) {
                abort(404);
            }
            DB::table('suppliers')->where('id', $supplier)->update([...$validated, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'supplier.updated',
                'subject_type' => 'supplier',
                'subject_id' => $supplier,
                'changes' => json_encode(['before' => $old, 'after' => $validated], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return back();
    }
}
