<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\ApprovalMonitor;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Support\OperationalRequestReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideInventoryAdjustment
{
    public function execute(int $requestId, User $reviewer, bool $approve, string $reason): void
    {
        DB::transaction(function () use ($requestId, $reviewer, $approve, $reason): void {
            $request = DB::table('inventory_adjustment_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null) {
                abort(404);
            }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This request has already been decided.']);
            }
            if ((int) $request->requester_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => 'You cannot approve your own request.']);
            }
            if (! $reviewer->hasAdministrationPermission('inventory.approve')) {
                abort(403);
            }
            $requester = User::query()->whereKey($request->requester_id)->firstOrFail();
            if ($approve && ! $requester->is_active) {
                throw ValidationException::withMessages(['decision' => 'The requester account is inactive.']);
            }

            if ($approve) {
                $product = Product::query()->whereKey($request->product_id)->lockForUpdate()->firstOrFail();
                $inventory = $product->inventory()->lockForUpdate()->first();
                $current = $inventory->quantity ?? 0;
                if ($current !== (int) $request->observed_quantity) {
                    throw ValidationException::withMessages(['decision' => 'Stock changed after this request. Submit a new request.']);
                }
                OperationalRequestReason::validateStockAdjustment($request->reason_code ?? 'other', $current, (int) $request->target_quantity, 'decision');
                if ($inventory === null) {
                    $inventory = Inventory::query()->create(['product_id' => $product->id, 'quantity' => 0, 'reorder_level' => $request->initial_reorder_level ?? 0]);
                }
                $inventory->quantity = (int) $request->target_quantity;
                $inventory->save();

                DB::table('inventory_movements')->insert([
                    'product_id' => $product->id,
                    'actor_id' => $reviewer->id,
                    'kind' => 'approved_adjustment',
                    'reference_type' => 'inventory_adjustment_request',
                    'reference_id' => $requestId,
                    'sellable_before' => $current,
                    'sellable_delta' => (int) $request->target_quantity - $current,
                    'sellable_after' => (int) $request->target_quantity,
                    'quarantined_before' => $inventory->quarantined_quantity,
                    'quarantined_delta' => 0,
                    'quarantined_after' => $inventory->quarantined_quantity,
                    'created_at' => now(),
                ]);
            }

            DB::table('inventory_adjustment_requests')->where('id', $requestId)->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewer_id' => $reviewer->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $reviewer->id,
                'action' => $approve ? 'inventory.adjustment.approved' : 'inventory.adjustment.rejected',
                'subject_type' => 'inventory_adjustment_request',
                'subject_id' => $requestId,
                'reason' => $reason,
                'changes' => json_encode(['requester_id' => $request->requester_id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            if ($approve) {
                app(ApprovalMonitor::class)->afterApproval($reviewer, (int) $request->requester_id);
            }
            app(OrderNotificationPublisher::class)->operationalAfterCommit($approve ? 'inventory.adjustment.approved' : 'inventory.adjustment.rejected', $requestId, (int) $request->requester_id, $reviewer->id);
        }, attempts: 3);
    }
}
