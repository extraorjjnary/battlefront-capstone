<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ApprovalConcentrationAlert;
use Illuminate\Support\Facades\DB;
use LogicException;

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

        $approvalQuery = DB::table('audit_logs')->where('actor_id', $reviewer->id)
            ->whereIn('action', [
                'inventory.adjustment.approved', 'damage.decided',
                'customer_return.accepted', 'supplier_return.approved',
            ])
            ->where('changes->requester_id', $requesterId)
            ->where('created_at', '>=', now()->subDay());
        $count = (clone $approvalQuery)->count();

        if ($count < 5 || $count % 5 !== 0) {
            return;
        }

        $decisions = $approvalQuery->orderByDesc('id')->limit(5)->get(['action', 'subject_id'])
            ->map(function ($approval): array {
                [$table, $quantityColumn] = match ($approval->action) {
                    'inventory.adjustment.approved' => ['inventory_adjustment_requests', null],
                    'damage.decided' => ['damage_reports', 'quantity'],
                    'customer_return.accepted' => ['customer_returns', null],
                    'supplier_return.approved' => ['supplier_returns', 'quantity'],
                    default => throw new LogicException('Unknown approval action.'),
                };
                $record = DB::table($table)->where('id', $approval->subject_id)->first();
                $quantity = match ($approval->action) {
                    'inventory.adjustment.approved' => $record === null ? null : abs($record->target_quantity - $record->observed_quantity),
                    'customer_return.accepted' => DB::table('customer_return_items')->where('customer_return_id', $approval->subject_id)->sum('quantity'),
                    default => $record?->{$quantityColumn},
                };
                $amount = $approval->action === 'customer_return.accepted' ? DB::table('customer_refunds')
                    ->where('customer_return_id', $approval->subject_id)->value('approved_amount') : null;
                $amount = is_numeric($amount) ? (string) $amount : null;

                return ['action' => $approval->action, 'subject_id' => (int) $approval->subject_id,
                    'quantity' => $quantity === null ? null : (int) $quantity, 'approved_amount' => $amount];
            })->values()->all();

        DB::table('audit_logs')->insert([
            'actor_id' => $reviewer->id,
            'action' => 'approval.concentration_alert',
            'subject_type' => 'user',
            'subject_id' => $requesterId,
            'changes' => json_encode(['reviewer_id' => $reviewer->id, 'approvals_in_24_hours' => $count,
                'recent_decisions' => $decisions], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        DB::afterCommit(function () use ($reviewer, $requester, $count, $decisions): void {
            $ownerRoleId = DB::table('administration_roles')->where('code', 'owner')->value('id');
            User::query()->where('role', UserRole::Administrator->value)
                ->where('administration_role_id', $ownerRoleId)->where('is_active', true)
                ->get()->each(fn (User $owner) => $owner->notify(new ApprovalConcentrationAlert($reviewer->name, $requester->name, $count, $decisions)));
        });
    }
}
