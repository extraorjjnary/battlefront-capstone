<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Models\Order;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use Illuminate\Support\Collection;

class NotificationRecipients
{
    public const array INVENTORY_EVENTS = [
        'inventory.adjustment.requested', 'inventory.adjustment.approved', 'inventory.adjustment.rejected',
        'damage.reported', 'damage.decided', 'supplier_return.requested', 'supplier_return.approved',
        'supplier_return.rejected', 'supplier_return.rejected_follow_up', 'supplier_return.dispatched',
        'supplier_return.closed', 'supplier_return.resolved', 'supplier_return.follow_up_resolved',
    ];

    /** @return Collection<int, User> */
    public function forEvent(OrderNotificationOccurred $event, UserRole $audience): Collection
    {
        if ($audience === UserRole::Customer) {
            return User::query()->where('role', $audience)->where('is_active', true)->whereKey($event->customerId)
                ->whereHas('orders', fn ($query) => $query->whereKey($event->orderId))->get();
        }

        $administrators = User::query()->where('role', UserRole::Administrator)->where('is_active', true)->get();
        foreach ($administrators->pluck('administration_role_id')->filter()->unique() as $roleId) {
            request()->attributes->remove('administration-role-permissions:'.$roleId);
        }
        $context = $event->context ?? [];
        $requesterId = $context['requester_id'] ?? null;
        $reviewerId = $context['reviewer_id'] ?? null;
        $order = $event->orderId === null ? null : Order::query()->find($event->orderId);
        $handler = $order === null ? null : $administrators->firstWhere('id', $order->assigned_handler_id);
        $handlerEligible = $handler !== null && $this->has($handler, ['orders.view', 'orders.process']);

        $preferred = match ($event->kind) {
            'supplier_return.approved' => $requesterId,
            'supplier_return.dispatched', 'supplier_return.closed' => $reviewerId,
            'customer_return.accepted', 'customer_return.rejected' => $requesterId,
            default => null,
        };
        $handoffPermissions = match ($event->kind) {
            'supplier_return.approved' => ['inventory.view', 'returns.dispatch'],
            'supplier_return.dispatched' => ['inventory.view', 'returns.approve'],
            'supplier_return.closed' => ['inventory.view', 'returns.resolve'],
            'customer_return.accepted', 'customer_return.rejected' => ['orders.view', 'returns.dispatch'],
            default => [],
        };
        $preferredUser = $administrators->firstWhere('id', $preferred);
        $usePreferred = $preferredUser !== null && $preferredUser->id !== ($context['actor_id'] ?? null)
            && $this->has($preferredUser, $handoffPermissions);

        return $administrators->filter(function (User $user) use ($event, $context, $requesterId, $reviewerId, $order, $handlerEligible, $preferred, $usePreferred, $handoffPermissions): bool {
            if (($event->kind !== 'order.handler.assigned' && $user->id === ($context['actor_id'] ?? null))
                || ! $this->canView($user, $event->kind)) {
                return false;
            }
            $isRequester = $user->id === $requesterId;
            $handoff = $handoffPermissions !== [] && $this->has($user, $handoffPermissions)
                && (! $usePreferred || $user->id === $preferred);

            return match ($event->kind) {
                'order.placed' => $user->can('orders.process') || $user->can('orders.assign'),
                'payment.proof_submitted' => $handlerEligible
                    ? $user->id === $order->assigned_handler_id
                    : ($order?->assigned_handler_id === null ? $user->can('orders.process') : $user->can('orders.assign')),
                'order.cancelled' => $user->can('orders.assign') || ($handlerEligible
                    ? $user->id === $order->assigned_handler_id : $user->can('orders.process')),
                'cancellation.requested' => in_array($user->administrationRoleCode(), ['manager', 'owner'], true) && $user->can('returns.approve'),
                'order.handler.assigned' => $user->id === ($context['previous_handler_id'] ?? null)
                    || ($user->id === ($context['handler_id'] ?? null) && $user->can('orders.process') && $order?->assigned_handler_id === $user->id),
                'customer_return.requested' => app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($user),
                'customer_return.received' => ! $isRequester && $user->can('returns.approve'),
                'customer_return.accepted' => $isRequester || (($context['manual_resolution'] ?? false) && $handoff),
                'customer_return.rejected' => $isRequester || $handoff,
                'customer_return.resolution_completed', 'customer_return.handed_back' => $isRequester,
                'inventory.adjustment.requested' => ! $isRequester && $user->can('inventory.approve'),
                'damage.reported', 'supplier_return.requested' => ! $isRequester && $user->can('returns.approve'),
                'supplier_return.rejected_follow_up' => $isRequester || ($user->id !== $reviewerId && $user->can('returns.approve')),
                'supplier_return.approved', 'supplier_return.dispatched', 'supplier_return.closed' => ($event->kind === 'supplier_return.approved' && $isRequester) || $handoff,
                'inventory.adjustment.approved', 'inventory.adjustment.rejected', 'damage.decided',
                'supplier_return.rejected', 'supplier_return.resolved', 'supplier_return.follow_up_resolved' => $isRequester,
                default => false,
            };
        })->values();
    }

    public function canView(User $user, string $kind): bool
    {
        return $user->hasAdministrationPermission(in_array($kind, self::INVENTORY_EVENTS, true) ? 'inventory.view' : 'orders.view');
    }

    /** @param list<string> $permissions */
    private function has(User $user, array $permissions): bool
    {
        return collect($permissions)->every(fn (string $permission): bool => $user->hasAdministrationPermission($permission));
    }
}
