<?php

namespace App\Repositories\Inventory;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryRepository
{
    /**
     * Return the filtered administrator inventory ledger.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateProducts(array $filters, string $stockFilter): LengthAwarePaginator
    {
        return Product::query()
            ->select(['id', 'product_code', 'name', 'category_id', 'brand', 'is_active'])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,quarantined_quantity,reorder_level,last_updated',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when(
                $filters['q'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%"),
                ),
            )
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $stockFilter === 'low_stock',
                fn (Builder $query): Builder => $query->whereHas('lowStockInventory'),
            )
            ->when(
                $stockFilter === 'in_stock',
                fn (Builder $query): Builder => $query->whereHas(
                    'inventory',
                    fn (Builder $inventoryQuery): Builder => $inventoryQuery
                        ->where('quantity', '>', 0)
                        ->whereColumn('quantity', '>', 'reorder_level'),
                ),
            )
            ->when(
                $stockFilter === 'out_of_stock',
                fn (Builder $query): Builder => $query->whereHas(
                    'inventory',
                    fn (Builder $inventoryQuery): Builder => $inventoryQuery->where('quantity', 0),
                ),
            )
            ->when(
                $stockFilter === 'not_initialized',
                fn (Builder $query): Builder => $query->doesntHave('inventory'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->appends($filters);
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'is_active'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function lowStockCount(): int
    {
        return Inventory::query()->lowStock()->count();
    }

    public function outOfStockCount(): int
    {
        return Inventory::query()->outOfStock()->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginateDamageReports(array $filters, int|string|null $case, bool $overdue, CarbonInterface $overdueCutoff): LengthAwarePaginator
    {
        return DB::table('damage_reports as reports')
            ->join('products', 'products.id', '=', 'reports.product_id')
            ->join('users', 'users.id', '=', 'reports.requester_id')
            ->select('reports.*', 'products.name as product_name', 'users.name as requester_name')
            ->when($case, fn ($query, $id) => $query->where('reports.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('reports.reason', 'like', '%'.$search.'%')))
            ->when($overdue, fn ($query) => $query->where('reports.status', 'pending')
                ->where('reports.created_at', '<', $overdueCutoff))
            ->orderByDesc('reports.created_at')->paginate(20)->withQueryString();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $reportIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function damageSupplierHolds(\Illuminate\Support\Collection $reportIds): \Illuminate\Support\Collection
    {
        return DB::table('supplier_returns')->whereIn('damage_report_id', $reportIds)
            ->whereIn('status', ['pending', 'approved', 'rejected_follow_up'])->pluck('damage_report_id')->flip();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $reportIds
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, \stdClass>>
     */
    public function damageEvidence(\Illuminate\Support\Collection $reportIds): \Illuminate\Support\Collection
    {
        return DB::table('administration_evidence')->where('subject_type', 'damage_report')
            ->whereIn('subject_id', $reportIds)
            ->get(['id', 'subject_id', 'original_name'])->groupBy('subject_id');
    }

    /** @return \Illuminate\Support\Collection<int, array{id: int, name: string, product_code: string, available_quantity: int}> */
    public function availableCaseProducts(): \Illuminate\Support\Collection
    {
        return Product::query()->whereHas('inventory', fn ($query) => $query->where('quantity', '>', 0))
            ->with('inventory:id,product_id,quantity')->orderBy('name')->get(['id', 'product_code', 'name'])
            ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code, 'available_quantity' => $product->inventory->quantity]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginateSupplierReturns(array $filters, int|string|null $case, bool $overdue, CarbonInterface $overdueCutoff): LengthAwarePaginator
    {
        return DB::table('supplier_returns as returns')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('suppliers', 'suppliers.id', '=', 'returns.supplier_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->leftJoin('supplier_return_resolutions as resolutions', 'resolutions.supplier_return_id', '=', 'returns.id')
            ->select('returns.*', 'products.name as product_name', 'suppliers.name as supplier_name', 'users.name as requester_name', 'resolutions.outcome as resolution_outcome', 'resolutions.external_reference as resolution_reference', 'resolutions.notes as resolution_notes')
            ->when($case, fn ($query, $id) => $query->where('returns.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('suppliers.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('returns.reason', 'like', '%'.$search.'%')
                ->orWhere('returns.external_reference', 'like', '%'.$search.'%')
                ->orWhere('returns.dispatch_reference', 'like', '%'.$search.'%')
                ->orWhere('returns.acknowledgement_reference', 'like', '%'.$search.'%')
                ->orWhere('resolutions.external_reference', 'like', '%'.$search.'%')))
            ->when($overdue, fn ($query) => $query->where(function ($query): void {
                $query->whereIn('returns.status', ['pending', 'approved', 'dispatched', 'rejected_follow_up'])
                    ->orWhere(fn ($query) => $query->where('returns.status', 'closed')->whereNull('resolutions.supplier_return_id'));
            })->where('returns.created_at', '<', $overdueCutoff))
            ->orderByDesc('returns.created_at')->paginate(20)->withQueryString();
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    public function activeSuppliers(): \Illuminate\Support\Collection
    {
        return DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginateAdjustments(array $filters, int|string|null $case, int $viewerId, bool $canApprove): LengthAwarePaginator
    {
        $requests = DB::table('inventory_adjustment_requests as requests')
            ->join('products', 'products.id', '=', 'requests.product_id')
            ->join('users', 'users.id', '=', 'requests.requester_id')
            ->leftJoin('inventories', 'inventories.product_id', '=', 'requests.product_id')
            ->select('requests.id', 'requests.requester_id', 'requests.replaces_request_id', 'requests.observed_quantity', 'requests.target_quantity', 'requests.initial_reorder_level', 'requests.reason_code', 'requests.reason', 'requests.decision_reason', 'requests.status', 'requests.created_at', 'products.name as product_name', 'users.name as requester_name')
            ->selectRaw('COALESCE(inventories.quantity, 0) as current_quantity')
            ->when($filters['view'] === 'mine', fn ($query) => $query->where('requests.requester_id', $viewerId))
            ->when($filters['view'] === 'to_review', fn ($query) => $query
                ->where('requests.status', 'pending')
                ->where('requests.requester_id', '!=', $viewerId)
                ->when(! $canApprove, fn ($query) => $query->whereRaw('1 = 0')))
            ->when($case, fn ($query, $id) => $query->where('requests.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('requests.reason', 'like', '%'.$search.'%')))
            ->orderByRaw("CASE WHEN requests.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requests.created_at')
            ->paginate(20)->withQueryString();
        $replacementIds = DB::table('inventory_adjustment_requests')
            ->whereIn('replaces_request_id', $requests->getCollection()->pluck('id'))
            ->pluck('id', 'replaces_request_id');
        $requests->getCollection()->transform(function ($entry) use ($replacementIds) {
            $entry->replacement_id = $replacementIds->get($entry->id);

            return $entry;
        });

        return $requests;
    }
}
