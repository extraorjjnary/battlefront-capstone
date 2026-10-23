<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Supplier\CreateSupplier;
use App\Actions\Supplier\UpdateSupplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function store(Request $request, CreateSupplier $create): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $create->execute($request->user(), $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supplier added.']);

        return back();
    }

    public function update(Request $request, int $supplier, UpdateSupplier $update): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($supplier)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'edit_version' => ['required', 'integer', 'min:1'],
        ]);
        $update->execute($request->user(), $supplier, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supplier updated.']);

        return back();
    }
}
