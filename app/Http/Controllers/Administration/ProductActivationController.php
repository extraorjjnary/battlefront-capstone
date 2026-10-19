<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateProductActivationRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProductActivationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateProductActivationRequest $request, Product $product): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        DB::transaction(function () use ($request, $product, $isActive): void {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($lockedProduct->is_active === $isActive) {
                return;
            }

            $before = $lockedProduct->is_active;
            $lockedProduct->is_active = $isActive;
            $lockedProduct->edit_version++;
            $lockedProduct->save();
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'action' => $isActive ? 'product.activated' : 'product.deactivated',
                'subject_type' => 'product',
                'subject_id' => $lockedProduct->id,
                'changes' => json_encode(['before' => ['is_active' => $before], 'after' => ['is_active' => $isActive]], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }, attempts: 3);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive
                ? __('Product reactivated.')
                : __('Product deactivated.'),
        ]);

        return back();
    }
}
