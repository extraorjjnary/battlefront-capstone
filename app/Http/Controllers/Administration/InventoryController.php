<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\InventoryIndexRequest;
use App\Http\Requests\Administration\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\Product;
use App\Repositories\Inventory\InventoryRepository;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display the administrator inventory ledger.
     */
    public function index(InventoryIndexRequest $request, InventoryRepository $inventoryRepository): Response
    {
        $filters = $request->validated();
        $filters['category_id'] = $request->filled('category_id')
            ? $request->integer('category_id')
            : null;
        $stockFilter = $filters['stock'] ?? 'all';

        $products = $inventoryRepository->paginateProducts($filters, $stockFilter)
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'brand' => $product->brand,
                'is_active' => $product->is_active,
                'is_low_stock' => $product->is_low_stock,
                'stock_status' => match (true) {
                    $product->inventory === null => 'not_initialized',
                    $product->inventory->quantity === 0 => 'out_of_stock',
                    $product->is_low_stock => 'low',
                    default => 'in_stock',
                },
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
                'categories' => $inventoryRepository->categories(),
            ],
            'low_stock_count' => $inventoryRepository->lowStockCount(),
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
