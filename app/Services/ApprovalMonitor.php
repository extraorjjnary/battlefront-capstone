<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ApprovalConcentrationAlert;
use Illuminate\Support\Facades\DB;

class ApprovalMonitor
{
    public function afterApproval(User $reviewer, int $requesterId): void
    {
        if ($reviewer->administrationRoleCode() !== 'manager') {
            return;
        }

        $requester = User::query()->find($requesterId);
        if ($requester?->administrationRoleCode() !== 'staff') {
            return;
        }

        $count = DB::table('audit_logs')->where('actor_id', $reviewer->id)
            ->whereIn('action', [
                'inventory.adjustment.approved', 'damage.decided',
                'customer_return.accepted', 'supplier_return.approved',
            ])
            ->where('changes->requester_id', $requesterId)
            ->where('created_at', '>=', now()->subDay())->count();

        if ($count < 5 || $count % 5 !== 0) {
            return;
        }

        DB::table('audit_logs')->insert([
            'actor_id' => $reviewer->id,
            'action' => 'approval.concentration_alert',
            'subject_type' => 'user',
            'subject_id' => $requesterId,
            'changes' => json_encode(['reviewer_id' => $reviewer->id, 'approvals_in_24_hours' => $count], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        DB::afterCommit(function () use ($reviewer, $requester, $count): void {
            $ownerRoleId = DB::table('administration_roles')->where('code', 'owner')->value('id');
            User::query()->where('role', UserRole::Administrator->value)
                ->where('administration_role_id', $ownerRoleId)->where('is_active', true)
                ->get()->each(fn (User $owner) => $owner->notify(new ApprovalConcentrationAlert($reviewer->name, $requester->name, $count)));
        });
    }
}
