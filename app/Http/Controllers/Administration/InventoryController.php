<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\InventoryIndexRequest;
use App\Http\Requests\Administration\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\Product;
use App\Repositories\Inventory\InventoryRepository;
use App\Support\OperationalRequestReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                    $product->is_low_stock => 'low_stock',
                    default => 'in_stock',
                },
                'category' => $product->category->name,
                'inventory' => $product->inventory === null ? null : [
                    'id' => $product->inventory->id,
                    'quantity' => $product->inventory->quantity,
                    'quarantined_quantity' => $product->inventory->quarantined_quantity,
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
            'out_of_stock_count' => $inventoryRepository->outOfStockCount(),
        ]);
    }

    /**
     * Initialize inventory for a product that does not have a record yet.
     */
    public function store(
        UpdateInventoryRequest $request,
        Product $product,
        RequestInventoryAdjustment $adjustment,
    ): RedirectResponse {
        if ($product->inventory()->exists()) {
            throw ValidationException::withMessages(['quantity' => 'Inventory is already initialized.']);
        }

        $adjustment->execute(
            $product,
            $request->user(),
            $request->integer('quantity'),
            OperationalRequestReason::resolve($request->string('reason_code')->toString(), $request->validated('reason'), openingStock: true, notesOptional: true),
            $request->integer('reorder_level'),
            reasonCode: $request->string('reason_code')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Opening stock submitted for approval.'),
        ]);

        return to_route('administration.inventory.index');
    }

    /**
     * Update an existing inventory record.
     */
    public function update(
        UpdateInventoryRequest $request,
        Inventory $inventory,
        RequestInventoryAdjustment $adjustment,
    ): RedirectResponse {
        if ($request->integer('reorder_level') !== $inventory->reorder_level) {
            throw ValidationException::withMessages(['reorder_level' => 'Change the reorder level separately from a stock request.']);
        }

        $adjustment->execute(
            $inventory->product,
            $request->user(),
            $request->integer('quantity'),
            OperationalRequestReason::resolve($request->string('reason_code')->toString(), $request->validated('reason'), notesOptional: true),
            reasonCode: $request->string('reason_code')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Stock change submitted for approval.'),
        ]);

        return back();
    }

    public function count(Request $request, Inventory $inventory, RequestInventoryAdjustment $adjustment): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'observed_quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['nullable', 'string', 'min:5', 'max:2000'],
            'reason_code' => ['required', Rule::in(['damage', 'theft_loss', 'found_stock', 'count_error', 'receiving_error', 'unit_of_measure_error', 'other'])],
        ]);

        $adjustment->execute(
            $inventory->product,
            $request->user(),
            (int) $validated['quantity'],
            'Physical sellable stock count: '.OperationalRequestReason::resolve($validated['reason_code'], $validated['reason'] ?? null, notesOptional: true),
            expectedQuantity: (int) $validated['observed_quantity'],
            reasonCode: $validated['reason_code'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Physical count submitted for approval.'),
        ]);

        return back();
    }

    public function updateReorderLevel(Request $request, Inventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'reorder_level' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $inventory, $validated): void {
            $locked = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $before = $locked->reorder_level;
            $locked->update(['reorder_level' => $validated['reorder_level']]);
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'inventory.reorder_level.updated',
                'subject_type' => 'inventory',
                'subject_id' => $locked->id,
                'reason' => $validated['reason'],
                'changes' => json_encode(['before' => $before, 'after' => $locked->reorder_level], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return back();
    }
}
