<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\InventoryIndexRequest;
use App\Http\Requests\Administration\UpdateInventoryRequest;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display the administrator inventory ledger.
     */
    public function index(InventoryIndexRequest $request): Response
    {
        $filters = $request->validated();
        $filters['category_id'] = $request->filled('category_id')
            ? $request->integer('category_id')
            : null;
        $stockFilter = ($filters['stock'] ?? null) === 'low' ? 'low' : 'all';

        $products = Product::query()
            ->select(['id', 'name', 'category_id', 'brand', 'is_active'])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level,last_updated',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when(
                $filters['q'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%"),
                ),
            )
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $stockFilter === 'low',
                fn (Builder $query): Builder => $query->whereHas('lowStockInventory'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->appends($filters)
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
                'q' => $filters['q'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'stock' => $stockFilter,
            ],
            'filter_options' => [
                'categories' => Category::query()
                    ->select(['id', 'name', 'is_active'])
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get(),
            ],
            'low_stock_count' => Inventory::query()->lowStock()->count(),
        ]);
    }

    /**
     * Initialize inventory for a product that does not have a record yet.
     */
    public function store(
        UpdateInventoryRequest $request,
        Product $product,
        AdjustInventoryStock $adjustInventoryStock,
    ): RedirectResponse {
        $inventory = $adjustInventoryStock->initialize(
            $product,
            $request->integer('quantity'),
            $request->integer('reorder_level'),
        );

        if (! $inventory->wasRecentlyCreated) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Inventory is already initialized.'),
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Inventory initialized.'),
        ]);

        return to_route('administration.inventory.index');
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
