<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
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

    /** @return array{version: int, event: string, audience: string, title: string, body: string, order_id: int, order_reference: string, occurred_at: string, cancellation_initiator?: string} */
    public function toDatabase(object $notifiable): array
    {
        $audience = $this->audience->value ?? $this->event->audience();
        $initiator = $this->event->cancellationInitiator ?? null;
        $title = match ($this->event->kind) {
            'order.placed' => 'New customer order',
            'payment.proof_submitted' => 'Payment proof awaiting review',
            'payment.verified' => 'Payment verified',
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

        if ($this->event->kind === 'order.cancelled' && $initiator !== null) {
            $data['cancellation_initiator'] = $initiator->value;
        }

        return $data;
    }
}
