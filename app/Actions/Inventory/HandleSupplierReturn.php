<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\User;
use App\Services\ApprovalMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandleSupplierReturn
{
    public function request(User $requester, int $supplierId, int $productId, int $quantity, string $reason): int
    {
        return DB::transaction(function () use ($requester, $supplierId, $productId, $quantity, $reason): int {
            if (! DB::table('suppliers')->where('id', $supplierId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier.']);
            }
            $inventory = Inventory::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
            if ($inventory->quantity < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Not enough sellable stock remains.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            $inventory->quantity -= $quantity;
            $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity + $quantity));
            $inventory->save();

            $id = DB::table('supplier_returns')->insertGetId([
                'supplier_id' => $supplierId,
                'product_id' => $productId,
                'requester_id' => $requester->id,
                'quantity' => $quantity,
                'status' => 'pending',
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->movement($inventory, $requester, 'supplier_return_hold', $id, $beforeSellable, $beforeHeld);
            $this->audit($requester, 'supplier_return.requested', $id, ['quantity' => $quantity, 'supplier_id' => $supplierId], $reason);

            return $id;
        }, attempts: 3);
    }

    public function decide(int $id, User $reviewer, bool $approve, ?string $reason = null): void
    {
        DB::transaction(function () use ($id, $reviewer, $approve, $reason): void {
            $return = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This supplier return was already decided.']);
            }
            if ((int) $return->requester_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => 'You cannot approve your own return.']);
            }
            if (! $reviewer->hasAdministrationPermission('returns.approve')) {
                abort(403);
            }

            if (! $approve && $return->damage_report_id === null && $return->customer_return_item_id === null) {
                $inventory = Inventory::query()->where('product_id', $return->product_id)->lockForUpdate()->firstOrFail();
                if ($inventory->quarantined_quantity < $return->quantity) {
                    throw ValidationException::withMessages(['decision' => 'Reserved stock is no longer available.']);
                }
                $beforeSellable = $inventory->quantity;
                $beforeHeld = $inventory->quarantined_quantity;
                $inventory->quantity += $return->quantity;
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $return->quantity));
                $inventory->save();
                $this->movement($inventory, $reviewer, 'supplier_return_released', $id, $beforeSellable, $beforeHeld);
            }

            DB::table('supplier_returns')->where('id', $id)->update([
                'status' => $approve ? 'approved' : (($return->damage_report_id !== null || $return->customer_return_item_id !== null) ? 'rejected_follow_up' : 'rejected'),
                'reviewer_id' => $reviewer->id,
                'decision_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->audit($reviewer, $approve ? 'supplier_return.approved' : 'supplier_return.rejected', $id, ['requester_id' => $return->requester_id], $reason);
            if ($approve) {
                app(ApprovalMonitor::class)->afterApproval($reviewer, (int) $return->requester_id);
            }
        }, attempts: 3);
    }

    public function dispatch(int $id, User $actor, string $externalReference): void
    {
        DB::transaction(function () use ($id, $actor, $externalReference): void {
            $return = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'approved') {
                throw ValidationException::withMessages(['dispatch' => 'Only an approved return may be dispatched once.']);
            }
            $inventory = Inventory::query()->where('product_id', $return->product_id)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $return->quantity) {
                throw ValidationException::withMessages(['dispatch' => 'Quarantined stock is no longer available.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $return->quantity));
            $inventory->save();
            $this->movement($inventory, $actor, 'supplier_return_dispatched', $id, $beforeSellable, $beforeHeld);
            DB::table('supplier_returns')->where('id', $id)->update([
                'status' => 'dispatched',
                'dispatch_reference' => $externalReference,
                'updated_at' => now(),
            ]);
            $this->audit($actor, 'supplier_return.dispatched', $id, ['external_reference' => $externalReference], null);
        }, attempts: 3);
    }

    public function acknowledge(int $id, User $actor, string $externalReference): void
    {
        DB::transaction(function () use ($id, $actor, $externalReference): void {
            $return = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'dispatched') {
                throw ValidationException::withMessages(['acknowledgement' => 'Only dispatched returns may be acknowledged.']);
            }
            DB::table('supplier_returns')->where('id', $id)->update([
                'status' => 'closed',
                'acknowledgement_reference' => $externalReference,
                'updated_at' => now(),
            ]);
            $this->audit($actor, 'supplier_return.closed', $id, ['external_reference' => $externalReference], null);
        }, attempts: 3);
    }

    public function followUp(int $id, User $actor, string $decision, string $reason, ?int $supplierId = null): void
    {
        DB::transaction(function () use ($id, $actor, $decision, $reason, $supplierId): void {
            $return = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'rejected_follow_up') {
                throw ValidationException::withMessages(['decision' => 'This supplier return does not need follow-up.']);
            }
            if ($return->damage_report_id === null && $return->customer_return_item_id === null) {
                throw ValidationException::withMessages(['decision' => 'This return has no linked quarantine case.']);
            }
            if ((int) $return->requester_id === $actor->id || (int) $return->reviewer_id === $actor->id) {
                throw ValidationException::withMessages(['decision' => 'Another reviewer must decide the follow-up.']);
            }
            if (! $actor->hasAdministrationPermission('returns.approve')) {
                abort(403);
            }
            if (! in_array($decision, ['retry', 'release', 'write_off'], true)) {
                throw ValidationException::withMessages(['decision' => 'Unknown follow-up decision.']);
            }

            $inventory = Inventory::query()->where('product_id', $return->product_id)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $return->quantity) {
                throw ValidationException::withMessages(['decision' => 'Quarantined stock is no longer available.']);
            }

            if ($decision === 'retry') {
                if ($supplierId === null || ! DB::table('suppliers')->where('id', $supplierId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier.']);
                }
                $newId = DB::table('supplier_returns')->insertGetId([
                    'supplier_id' => $supplierId,
                    'product_id' => $return->product_id,
                    'requester_id' => $actor->id,
                    'damage_report_id' => $return->damage_report_id,
                    'customer_return_item_id' => $return->customer_return_item_id,
                    'previous_supplier_return_id' => $id,
                    'quantity' => $return->quantity,
                    'status' => 'pending',
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $beforeSellable = $inventory->quantity;
                $beforeHeld = $inventory->quarantined_quantity;
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $return->quantity));
                if ($decision === 'release') {
                    $inventory->quantity += $return->quantity;
                }
                $inventory->save();
                $this->movement($inventory, $actor, 'supplier_return_'.$decision, $id, $beforeSellable, $beforeHeld);
                $newId = null;
            }

            DB::table('supplier_returns')->where('id', $id)->update([
                'status' => $decision === 'retry' ? 'superseded' : 'resolved',
                'updated_at' => now(),
            ]);
            $this->audit($actor, 'supplier_return.follow_up', $id, [
                'decision' => $decision,
                'next_supplier_return_id' => $newId,
            ], $reason);
        }, attempts: 3);
    }

    public function resolve(int $id, User $actor, string $outcome, string $externalReference, string $notes): void
    {
        DB::transaction(function () use ($id, $actor, $outcome, $externalReference, $notes): void {
            $return = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'closed' || DB::table('supplier_return_resolutions')->where('supplier_return_id', $id)->exists()) {
                throw ValidationException::withMessages(['outcome' => 'Only an acknowledged, unresolved supplier return can be resolved.']);
            }
            if (! $actor->hasAdministrationPermission('returns.resolve')) {
                abort(403);
            }

            DB::table('supplier_return_resolutions')->insert([
                'supplier_return_id' => $id,
                'recorded_by' => $actor->id,
                'outcome' => $outcome,
                'external_reference' => $externalReference,
                'notes' => $notes,
                'created_at' => now(),
            ]);
            $this->audit($actor, 'supplier_return.resolved', $id, [
                'outcome' => $outcome,
                'external_reference' => $externalReference,
            ], $notes);
        }, attempts: 3);
    }

    private function movement(Inventory $inventory, User $actor, string $kind, int $returnId, int $beforeSellable, int $beforeHeld): void
    {
        DB::table('inventory_movements')->insert([
            'product_id' => $inventory->product_id,
            'actor_id' => $actor->id,
            'kind' => $kind,
            'reference_type' => 'supplier_return',
            'reference_id' => $returnId,
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
            'subject_type' => 'supplier_return',
            'subject_id' => $id,
            'reason' => $reason,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
