<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Models\User;
use Illuminate\Notifications\Notification;

class OrderUpdateNotification extends Notification
{
    public function __construct(
        public readonly OrderNotificationOccurred $event,
        public readonly ?UserRole $audience = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $audience = $this->audience->value ?? $this->event->audience();
        $initiator = $this->event->cancellationInitiator ?? null;
        $context = $this->event->context ?? [];
        $title = match ($this->event->kind) {
            'order.placed' => 'New customer order',
            'payment.proof_submitted' => 'Payment proof awaiting review',
            'payment.verified' => 'Payment verified',
            'refund.completed' => 'Refund completed',
            'cancellation.requested' => 'Cancellation requested',
            'cancellation.approved' => 'Cancellation approved',
            'cancellation.rejected' => 'Cancellation request rejected',
            'order.handler.assigned' => (int) $notifiable->id === ($context['handler_id'] ?? null) ? 'Order assigned to you' : 'Order responsibility transferred',
            'customer_return.requested' => 'Return request awaiting receipt',
            'customer_return.received' => $audience === 'customer' ? 'Returned item received' : 'Return awaiting review',
            'customer_return.accepted' => 'Return approved',
            'customer_return.rejected' => 'Return rejected',
            'customer_return.resolution_completed' => 'Return resolution completed',
            'customer_return.handed_back' => 'Returned item handed back',
            'inventory.adjustment.requested' => 'Stock request awaiting review',
            'inventory.adjustment.approved' => 'Stock request approved',
            'inventory.adjustment.rejected' => 'Stock request rejected',
            'damage.reported' => 'Damage report awaiting review',
            'damage.decided' => 'Damage disposition recorded',
            'supplier_return.requested' => 'Supplier return awaiting review',
            'supplier_return.approved' => 'Supplier return approved for dispatch',
            'supplier_return.rejected' => 'Supplier return rejected',
            'supplier_return.rejected_follow_up' => 'Supplier return needs follow-up',
            'supplier_return.dispatched' => 'Supplier acknowledgment required',
            'supplier_return.closed' => 'Supplier return awaiting resolution',
            'supplier_return.resolved', 'supplier_return.follow_up_resolved' => 'Supplier return resolved',
            'payment.rejected' => 'Payment rejected',
            'order.cancelled' => 'Order cancelled',
            'shipment.manual_created' => 'Delivery arrangement recorded',
            'shipment.preparing' => 'Preparing your shipment',
            'shipment.ready_for_dispatch' => 'Shipment ready for dispatch',
            'shipment.handed_to_lbc' => 'Shipment handed to LBC',
            'shipment.in_transit' => 'Shipment in transit',
            'shipment.out_for_delivery' => 'Shipment out for delivery',
            'shipment.delivered' => 'Shipment delivered',
            default => throw new \InvalidArgumentException('Unsupported notification event.'),
        };

        $body = $audience === 'administrator'
            ? 'Open order '.$this->event->orderReference.' to review the next action.'
            : 'There is an update for order '.$this->event->orderReference.'. Open your order for details.';

        if ($audience === 'customer') {
            $body = match ($this->event->kind) {
                'customer_return.received' => 'We received your returned item. Your return is awaiting review.',
                'customer_return.accepted' => 'Your return was approved. Open your order for the agreed resolution.',
                'customer_return.rejected' => 'Your return was rejected. Open your order for the decision details.',
                'customer_return.resolution_completed' => 'Your approved return resolution is complete. Open your order for details.',
                'cancellation.requested' => 'We received your cancellation request. Order handling is paused while it is reviewed.',
                'cancellation.approved' => 'Your cancellation was approved. Open your order for details.',
                'cancellation.rejected' => 'Your cancellation request was rejected. Open your order for the decision details.',
                'refund.completed' => 'Your approved refund is fully confirmed. Open your order for payment details and available proof.',
                default => $body,
            };
            if (($context['refund_processing'] ?? false) && in_array($this->event->kind, ['cancellation.approved', 'customer_return.accepted'], true)) {
                $title = 'Refund processing';
                $body = ($this->event->kind === 'cancellation.approved' ? 'Your cancellation' : 'Your return').' was approved. Your refund is awaiting payment confirmation.';
            }
        }

        if ($this->event->kind === 'order.cancelled' && $initiator !== null) {
            if ($initiator === UserRole::Customer && $audience === 'administrator') {
                $title = 'Order cancelled by customer';
                $body = 'The customer cancelled order '.$this->event->orderReference.'. Open the order to review its details.';
            } elseif ($initiator === UserRole::Customer) {
                $title = 'Order cancellation confirmed';
                $body = 'You cancelled order '.$this->event->orderReference.'. It remains in your order history.';
            } else {
                $title = 'Order cancelled by Battlefront';
                $body = 'Battlefront cancelled your order '.$this->event->orderReference.'. Open your order for details.';
            }
        }

        $data = [
            'version' => 1,
            'event' => $this->event->kind,
            'audience' => $audience,
            'title' => $title,
            'body' => $body,
            'order_id' => $this->event->orderId,
            'order_reference' => $this->event->orderReference,
            'occurred_at' => $this->event->occurredAt,
        ];

        if ($audience === 'administrator' && isset($context['subject_id'])) {
            $type = match (true) {
                str_starts_with($this->event->kind, 'inventory.adjustment.') => 'stock_adjustment',
                str_starts_with($this->event->kind, 'damage.') => 'damage_report',
                str_starts_with($this->event->kind, 'supplier_return.') => 'supplier_return',
                $this->event->kind === 'customer_return.requested' => 'customer_return_request',
                str_starts_with($this->event->kind, 'customer_return.') => 'customer_return',
                default => null,
            };
            if ($type !== null) {
                $data['subject'] = ['type' => $type, 'id' => $context['subject_id']];
                $data['body'] = 'Open the case to review this update and any next action.';
            }
        }
        if ($this->event->kind === 'order.handler.assigned') {
            $data['body'] = (int) $notifiable->id === ($context['handler_id'] ?? null)
                ? 'You are now responsible for order '.$this->event->orderReference.'. Open it to review the next action.'
                : 'Responsibility for order '.$this->event->orderReference.' has been transferred to another handler.';
        }

        if ($this->event->kind === 'order.cancelled' && $initiator !== null) {
            $data['cancellation_initiator'] = $initiator->value;
        }

        return $data;
    }
}
