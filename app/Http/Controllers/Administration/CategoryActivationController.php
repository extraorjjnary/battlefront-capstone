<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateCategoryActivationRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryActivationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateCategoryActivationRequest $request, Category $category): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        $category->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive
                ? __('Category reactivated.')
                : __('Category deactivated.'),
        ]);

        return back();
    }
}
