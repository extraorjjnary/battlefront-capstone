<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveBranchRequest;
use App\Models\Branch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('Administration/Branches/Index', [
            'branches' => Branch::query()
                ->orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [config('battlefront.operational_branch_city')])
                ->orderBy('city')
                ->orderBy('id')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Administration/Branches/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SaveBranchRequest $request): RedirectResponse
    {
        try {
            Branch::query()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['city' => 'A branch in this city already exists.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Branch created.']);

        return to_route('administration.branches.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch): Response
    {
        return Inertia::render('Administration/Branches/Show', ['branch' => $branch]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Branch $branch): Response
    {
        return Inertia::render('Administration/Branches/Edit', ['branch' => $branch]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SaveBranchRequest $request, Branch $branch): RedirectResponse
    {
        try {
            $branch->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['city' => 'A branch in this city already exists.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Branch updated.']);

        return to_route('administration.branches.index');
    }
}
