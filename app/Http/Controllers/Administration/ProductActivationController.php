<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateProductActivationRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProductActivationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateProductActivationRequest $request, Product $product): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        $product->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive
                ? __('Product reactivated.')
                : __('Product deactivated.'),
        ]);

        return back();
    }
}
