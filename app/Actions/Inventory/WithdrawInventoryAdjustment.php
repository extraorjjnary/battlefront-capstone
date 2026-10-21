<?php

namespace App\Actions\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawInventoryAdjustment
{
    public function execute(int $requestId, User $actor, string $reason): void
    {
        DB::transaction(function () use ($requestId, $actor, $reason): void {
            $request = DB::table('inventory_adjustment_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                abort(404);
            }
            if ((int) $request->requester_id !== $actor->id) {
                abort(403);
            }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['withdrawal' => 'Only pending requests can be withdrawn.']);
            }

            DB::table('inventory_adjustment_requests')->where('id', $requestId)->update([
                'status' => 'withdrawn',
                'decision_reason' => $reason,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'inventory.adjustment.withdrawn',
                'subject_type' => 'inventory_adjustment_request',
                'subject_id' => $requestId,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
