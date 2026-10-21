<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\ApprovalMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandleDamage
{
    public function report(Product $product, User $reporter, int $quantity, string $reason, string $reasonCode): int
    {
        return DB::transaction(function () use ($product, $reporter, $quantity, $reason, $reasonCode): int {
            $inventory = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            if ($inventory->quantity < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Not enough sellable stock remains to quarantine.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            $inventory->quantity -= $quantity;
            $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity + $quantity));
            $inventory->save();

            $id = DB::table('damage_reports')->insertGetId([
                'product_id' => $product->id,
                'requester_id' => $reporter->id,
                'quantity' => $quantity,
                'status' => 'pending',
                'reason' => $reason,
                'reason_code' => $reasonCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->movement($inventory, $reporter, 'damage_hold', 'damage_report', $id, $beforeSellable, $beforeHeld);
            $this->audit($reporter, 'damage.reported', $id, ['product_id' => $product->id, 'quantity' => $quantity], $reason);

            return $id;
        }, attempts: 3);
    }

    public function decide(int $damageId, User $reviewer, string $disposition, string $reason, ?int $supplierId = null, bool $inspectionConfirmed = false): void
    {
        DB::transaction(function () use ($damageId, $reviewer, $disposition, $reason, $supplierId, $inspectionConfirmed): void {
            $report = DB::table('damage_reports')->where('id', $damageId)->lockForUpdate()->first();
            if ($report === null) {
                abort(404);
            }
            if ($report->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This damage report was already decided.']);
            }
            if ((int) $report->requester_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => 'You cannot decide your own damage report.']);
            }
            if (! $reviewer->hasAdministrationPermission('returns.approve')) {
                abort(403);
            }

            $inventory = Inventory::query()->where('product_id', $report->product_id)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $report->quantity) {
                throw ValidationException::withMessages(['decision' => 'The quarantined quantity is no longer available.']);
            }
            if ($disposition === 'write_off' && ! DB::table('administration_evidence')->where('subject_type', 'damage_report')->where('subject_id', $damageId)->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Attach evidence before writing off damaged stock.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            if ($disposition === 'release') {
                if (! $inspectionConfirmed) {
                    throw ValidationException::withMessages(['inspection_confirmed' => 'Confirm inspection before releasing stock for sale.']);
                }
                $inventory->quantity += $report->quantity;
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $report->quantity));
                $inventory->save();
                $this->movement($inventory, $reviewer, 'damage_release', 'damage_report', $damageId, $beforeSellable, $beforeHeld);
            } elseif ($disposition === 'write_off') {
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $report->quantity));
                $inventory->save();
                $this->movement($inventory, $reviewer, 'damage_write_off', 'damage_report', $damageId, $beforeSellable, $beforeHeld);
            } elseif ($disposition === 'supplier_return') {
                if ($supplierId === null || ! DB::table('suppliers')->where('id', $supplierId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier.']);
                }
                DB::table('supplier_returns')->insert([
                    'supplier_id' => $supplierId,
                    'product_id' => $report->product_id,
                    'requester_id' => $reviewer->id,
                    'damage_report_id' => $damageId,
                    'quantity' => $report->quantity,
                    'status' => 'pending',
                    'reason' => $report->reason,
                    'reason_code' => $report->reason_code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                throw ValidationException::withMessages(['decision' => 'Unknown damage disposition.']);
            }

            DB::table('damage_reports')->where('id', $damageId)->update([
                'status' => 'approved',
                'disposition' => $disposition,
                'reviewer_id' => $reviewer->id,
                'decision_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->audit($reviewer, 'damage.decided', $damageId, ['disposition' => $disposition, 'requester_id' => $report->requester_id], $reason);
            app(ApprovalMonitor::class)->afterApproval($reviewer, (int) $report->requester_id);
        }, attempts: 3);
    }

    private function movement(Inventory $inventory, User $actor, string $kind, string $referenceType, int $referenceId, int $beforeSellable, int $beforeHeld): void
    {
        DB::table('inventory_movements')->insert([
            'product_id' => $inventory->product_id,
            'actor_id' => $actor->id,
            'kind' => $kind,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'sellable_before' => $beforeSellable,
            'sellable_delta' => $inventory->quantity - $beforeSellable,
            'sellable_after' => $inventory->quantity,
            'quarantined_before' => $beforeHeld,
            'quarantined_delta' => $inventory->quarantined_quantity - $beforeHeld,
            'quarantined_after' => $inventory->quarantined_quantity,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(User $actor, string $action, int $id, array $changes, ?string $reason): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => 'damage_report',
            'subject_id' => $id,
            'reason' => $reason,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
