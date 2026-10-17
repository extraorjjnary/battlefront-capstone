<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignOrderHandler
{
    public function execute(Order $order, User $actor, int $handlerId, bool $claimOnly = false): void
    {
        DB::transaction(function () use ($order, $actor, $handlerId, $claimOnly): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['handler' => 'Completed or cancelled orders cannot be reassigned.']);
            }
            if ($claimOnly && ($locked->assigned_handler_id !== null || $handlerId !== $actor->id)) {
                throw ValidationException::withMessages(['handler' => 'Only an unassigned order may be claimed for yourself.']);
            }
            if (! $claimOnly && ! $actor->hasAdministrationPermission('orders.assign')) {
                abort(403);
            }
            $handler = User::query()->whereKey($handlerId)->where('role', UserRole::Administrator->value)
                ->where('is_active', true)->whereNotNull('administration_role_id')->first();
            if ($handler === null || ! $handler->hasAdministrationPermission('orders.process')) {
                throw ValidationException::withMessages(['handler' => 'Choose an active administrator who can process orders.']);
            }

            $before = $locked->assigned_handler_id;
            $locked->assigned_handler_id = $handlerId;
            $locked->save();
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'order.handler.assigned',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'changes' => json_encode(['before' => $before, 'after' => $handlerId], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
