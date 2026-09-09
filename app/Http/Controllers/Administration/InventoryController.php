<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display the administrator inventory ledger.
     */
    public function index(Request $request): Response
    {
        $stockFilter = $request->string('stock')->toString() === 'low'
            ? 'low'
            : 'all';

        $products = Product::query()
            ->select(['id', 'name', 'category_id', 'brand', 'is_active'])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level,last_updated',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when(
                $stockFilter === 'low',
                fn (Builder $query): Builder => $query->whereHas('lowStockInventory'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'brand' => $product->brand,
                'is_active' => $product->is_active,
                'is_low_stock' => $product->is_low_stock,
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
            'filters' => [
                'stock' => $stockFilter,
            ],
            'low_stock_count' => Inventory::query()->lowStock()->count(),
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
