<?php

namespace App\Services\Reporting;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockReconciliationReport
{
    /** @return array<string, mixed> */
    public function page(?int $productId, User $actor, ?string $search = null): array
    {
        $inventories = DB::table('inventories')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->whereNull('products.deleted_at')
            ->when($search, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('products.name', 'like', '%'.$search.'%')
                ->orWhere('products.product_code', 'like', '%'.$search.'%')))
            ->select('inventories.product_id', 'inventories.quantity', 'inventories.quarantined_quantity', 'products.name', 'products.product_code')
            ->orderBy('products.name')->orderBy('products.id')
            ->paginate(20)->withQueryString();
        $ids = $inventories->getCollection()->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
        $held = array_fill_keys($ids, 0);

        $damage = DB::table('damage_reports')->select('product_id')
            ->selectRaw('SUM(quantity) as held')->where('status', 'pending')->whereIn('product_id', $ids)->groupBy('product_id')->get();
        $customer = DB::table('customer_returns as returns')
            ->join('customer_return_items as items', 'items.customer_return_id', '=', 'returns.id')
            ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
            ->select('order_items.product_id')->selectRaw('SUM(items.quantity) as held')
            ->whereIn('returns.status', ['pending', 'rejected_pending_handover'])
            ->whereIn('order_items.product_id', $ids)->groupBy('order_items.product_id')->get();
        $supplier = DB::table('supplier_returns')->select('product_id')
            ->selectRaw('SUM(quantity) as held')
            ->whereIn('status', ['pending', 'approved', 'rejected_follow_up'])
            ->whereIn('product_id', $ids)->groupBy('product_id')->get();
        foreach ([$damage, $customer, $supplier] as $cases) {
            foreach ($cases as $case) {
                $held[$case->product_id] += (int) $case->held;
            }
        }

        $ledger = [];
        foreach (DB::table('inventory_movements')->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('id')->cursor() as $movement) {
            $id = (int) $movement->product_id;
            $previous = $ledger[$id] ?? null;
            $gap = (int) $movement->sellable_after !== (int) $movement->sellable_before + (int) $movement->sellable_delta
                || (int) $movement->quarantined_after !== (int) $movement->quarantined_before + (int) $movement->quarantined_delta
                || ($previous !== null && ((int) $movement->sellable_before !== $previous['sellable']
                    || (int) $movement->quarantined_before !== $previous['quarantined']));
            $ledger[$id] = [
                'sellable' => (int) $movement->sellable_after,
                'quarantined' => (int) $movement->quarantined_after,
                'gap' => $gap || ($previous['gap'] ?? false),
            ];
        }

        $inventories->through(function ($inventory) use ($held, $ledger): array {
            $id = (int) $inventory->product_id;
            $last = $ledger[$id] ?? null;
            $caseGap = (int) $inventory->quarantined_quantity !== $held[$id];

            return [
                'product_id' => $id,
                'product_code' => $inventory->product_code,
                'name' => $inventory->name,
                'sellable' => (int) $inventory->quantity,
                'quarantined' => (int) $inventory->quarantined_quantity,
                'case_expected' => $held[$id],
                'ledger_sellable' => $last['sellable'] ?? null,
                'ledger_quarantined' => $last['quarantined'] ?? null,
                'status' => $last === null ? ($caseGap ? 'discrepancy' : 'legacy_unknown')
                    : (($last['gap'] || $last['sellable'] !== (int) $inventory->quantity
                        || $last['quarantined'] !== (int) $inventory->quarantined_quantity || $caseGap)
                        ? 'discrepancy' : 'matched'),
            ];
        });

        $movements = $productId === null ? null : DB::table('inventory_movements as movements')
            ->leftJoin('users', 'users.id', '=', 'movements.actor_id')
            ->where('movements.product_id', $productId)
            ->select('movements.*', 'users.name as actor_name')
            ->orderByDesc('movements.id')->paginate(25, ['*'], 'movement_page')->withQueryString()
            ->through(fn ($movement): array => [
                'id' => $movement->id,
                'created_at' => $movement->created_at,
                'kind' => $movement->kind,
                'actor_name' => $movement->actor_name ?? 'System',
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'href' => $this->sourceLink($movement->reference_type, $movement->reference_id, $actor),
                'sellable_before' => $movement->sellable_before,
                'sellable_delta' => $movement->sellable_delta,
                'sellable_after' => $movement->sellable_after,
                'quarantined_before' => $movement->quarantined_before,
                'quarantined_delta' => $movement->quarantined_delta,
                'quarantined_after' => $movement->quarantined_after,
            ]);

        return [
            'inventories' => $inventories,
            'selected_product_id' => $productId,
            'movements' => $movements,
            'filters' => ['q' => $search],
        ];
    }

    private function sourceLink(?string $type, ?int $id, User $actor): ?string
    {
        if ($id === null) {
            return null;
        }

        $route = match ($type) {
            'inventory_adjustment_request' => 'administration.inventory-adjustments.index',
            'damage_report' => 'administration.damage-reports.index',
            'supplier_return' => 'administration.supplier-returns.index',
            'customer_return' => $actor->can('orders.view') ? 'administration.customer-returns.index' : null,
            'order' => $actor->can('orders.view') ? 'administration.orders.show' : null,
            default => null,
        };

        return $route === null ? null : ($type === 'order' ? route($route, $id) : route($route, ['case' => $id]));
    }
}
