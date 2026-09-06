<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display the administrator inventory ledger.
     */
    public function index(): Response
    {
        $products = Product::query()
            ->select(['id', 'name', 'category_id', 'brand', 'is_active'])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level,last_updated',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'brand' => $product->brand,
                'is_active' => $product->is_active,
                'category' => $product->category->name,
                'inventory' => $product->inventory === null ? null : [
                    'id' => $product->inventory->id,
                    'quantity' => $product->inventory->quantity,
                    'reorder_level' => $product->inventory->reorder_level,
                    'last_updated' => $product->inventory->last_updated->toIso8601String(),
                ],
            ]);

        return Inertia::render('Administration/Inventory', [
            'products' => $products,
        ]);
    }

    /**
     * Update an existing inventory record.
     */
    public function update(
        UpdateInventoryRequest $request,
        Inventory $inventory,
        AdjustInventoryStock $adjustInventoryStock,
    ): RedirectResponse {
        $adjustInventoryStock->set(
            $inventory,
            $request->integer('quantity'),
            $request->integer('reorder_level'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Inventory updated.'),
        ]);

        return back();
    }
}
