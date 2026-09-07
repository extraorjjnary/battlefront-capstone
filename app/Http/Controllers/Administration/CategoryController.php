<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $categories = Category::query()
            ->select(['id', 'name', 'description', 'is_active'])
            ->withCount('products')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Administration/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Administration/Categories/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        Category::query()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category created.'),
        ]);

        return to_route('administration.categories.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category): Response
    {
        return Inertia::render('Administration/Categories/Edit', [
            'category' => $category->only([
                'id',
                'name',
                'description',
                'is_active',
            ]),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SaveCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category updated.'),
        ]);

        return to_route('administration.categories.index');
    }
}
