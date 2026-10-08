<?php

namespace App\Notifications;

use App\Events\OrderNotificationOccurred;
use Illuminate\Notifications\Notification;

class OrderUpdateNotification extends Notification
{
    public function __construct(public readonly OrderNotificationOccurred $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{version: int, event: string, audience: string, title: string, body: string, order_id: int, order_reference: string, occurred_at: string} */
    public function toDatabase(object $notifiable): array
    {
        $title = match ($this->event->kind) {
            'order.placed' => 'New customer order',
            'payment.proof_submitted' => 'Payment proof awaiting review',
            'payment.verified' => 'Payment verified',
            'payment.rejected' => 'Payment rejected',
            'order.cancelled' => 'Order cancelled',
            'shipment.preparing' => 'Preparing your shipment',
            'shipment.ready_for_dispatch' => 'Shipment ready for dispatch',
            'shipment.handed_to_lbc' => 'Shipment handed to LBC',
            'shipment.in_transit' => 'Shipment in transit',
            'shipment.out_for_delivery' => 'Shipment out for delivery',
            'shipment.delivered' => 'Shipment delivered',
            default => throw new \InvalidArgumentException('Unsupported notification event.'),
        };

        return [
            'version' => 1,
            'event' => $this->event->kind,
            'audience' => $this->event->audience(),
            'title' => $title,
            'body' => $this->event->audience() === 'administrator'
                ? 'Open order '.$this->event->orderReference.' to review the next action.'
                : 'There is an update for order '.$this->event->orderReference.'. Open your order for details.',
            'order_id' => $this->event->orderId,
            'order_reference' => $this->event->orderReference,
            'occurred_at' => $this->event->occurredAt,
        ];
    }
}
