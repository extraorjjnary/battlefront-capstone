<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Catalog\CatalogName;
use App\Actions\Category\DeleteCategory;
use App\Actions\Category\RestoreCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveCategoryRequest;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $showDeleted = $request->boolean('deleted');
        if ($showDeleted) {
            $this->authorize('catalog.delete');
        }

        $query = $showDeleted ? Category::onlyTrashed() : Category::query();
        $categories = $query
            ->select(['id', 'name', 'description', 'is_active', ...($showDeleted ? ['deleted_at', 'deleted_by_id'] : [])])
            ->when($showDeleted, fn ($query) => $query->with('deletedBy:id,name'))
            ->withCount($showDeleted
                ? ['products' => fn ($products) => $products->withTrashed()]
                : 'products')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->through(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'products_count' => (int) $category->products_count,
                ...($showDeleted ? [
                    'deleted_at' => $category->deleted_at?->toDateTimeString(),
                    'deleted_by' => $category->deletedBy?->name,
                ] : []),
            ])
            ->withQueryString();

        return Inertia::render('Administration/Categories/Index', [
            'categories' => $categories,
            'deleted' => $showDeleted,
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
        try {
            Category::query()->create($request->validated());
        } catch (UniqueConstraintViolationException $exception) {
            CatalogName::handleUniqueFailure($exception, 'categories', CatalogName::CATEGORY_MESSAGE);
        }

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
        try {
            $category->update($request->validated());
        } catch (UniqueConstraintViolationException $exception) {
            CatalogName::handleUniqueFailure($exception, 'categories', CatalogName::CATEGORY_MESSAGE);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category updated.'),
        ]);

        return to_route('administration.categories.index');
    }

    public function destroy(Category $category, DeleteCategory $deleteCategory): RedirectResponse
    {
        $this->authorize('catalog.delete');
        $deleteCategory->execute($category);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category moved to Deleted categories. An owner can restore it.'),
        ]);

        return to_route('administration.categories.index');
    }

    public function restore(int $category, RestoreCategory $restoreCategory): RedirectResponse
    {
        $this->authorize('catalog.delete');
        $restoreCategory->execute($category);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category restored as inactive.')]);

        return to_route('administration.categories.index', ['deleted' => 1]);
    }
}
