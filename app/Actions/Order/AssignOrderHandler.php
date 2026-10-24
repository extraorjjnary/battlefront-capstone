<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignOrderHandler
{
    public function __construct(private readonly OrderProcessingService $processing) {}

    public function execute(Order $order, User $actor, int $handlerId, bool $claimOnly = false): void
    {
        DB::transaction(function () use ($order, $actor, $handlerId, $claimOnly): void {
            abort_unless($actor->hasAdministrationPermission($claimOnly ? 'orders.process' : 'orders.assign'), 403);
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['handler' => 'Completed or cancelled orders cannot be reassigned.']);
            }
            $this->processing->assertNoCancellationReview($locked, 'handler');
            if ($claimOnly && ($locked->assigned_handler_id !== null || $handlerId !== $actor->id)) {
                throw ValidationException::withMessages(['handler' => 'Only an unassigned order may be claimed for yourself.']);
            }
            $handler = User::query()->whereKey($handlerId)->where('role', UserRole::Administrator->value)
                ->where('is_active', true)->whereNotNull('administration_role_id')->first();
            if ($handler === null || ! $handler->hasAdministrationPermission('orders.process')) {
                throw ValidationException::withMessages(['handler' => 'Choose an active administrator who can process orders.']);
            }

            $this->assignLocked($locked, $actor, $handler);
        }, attempts: 3);
    }

    private function assignLocked(Order $order, User $actor, User $handler): void
    {
        $before = $order->assigned_handler_id;
        $order->assigned_handler_id = $handler->id;
        $order->save();

        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => 'order.handler.assigned',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'changes' => json_encode(['before' => $before, 'after' => $handler->id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
