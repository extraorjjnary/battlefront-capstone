<?php

namespace App\Services;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonInterface;

class ShipmentPresenter
{
    public const NOTICE = 'Shipment status is manually maintained by Battlefront. This is not live LBC or GPS tracking.';

    /** @return array<string, mixed>|null */
    public function detail(Order $order): ?array
    {
        $shipment = $order->shipment;
        if ($order->fulfillment_method !== FulfillmentMethod::Delivery || $shipment === null) {
            return null;
        }

        $status = $order->status === OrderStatus::Cancelled ? ShipmentStatus::Cancelled : $shipment->status;

        return [
            'carrier' => $shipment->carrier,
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'tracking_reference' => filled($shipment->tracking_reference) ? $shipment->tracking_reference : null,
            'eta' => [
                'anchor_date' => $shipment->eta_anchor_date?->toDateString(),
                'timezone' => $shipment->eta_timezone,
                'estimated_delivery_start' => $shipment->estimated_delivery_start?->toDateString(),
                'estimated_delivery_end' => $shipment->estimated_delivery_end?->toDateString(),
                'notice' => 'Battlefront estimate from the start of preparation; arrival is not guaranteed.',
            ],
            'timeline' => $this->timeline($shipment),
            'notice' => self::NOTICE,
            'history_notice' => $order->status === OrderStatus::Completed && $shipment->status !== ShipmentStatus::Delivered
                ? 'This order was completed before the shipment workflow was introduced. Only recorded milestones are shown.'
                : null,
        ];
    }

    /** @return list<array{status: string, label: string, occurred_at: string}> */
    private function timeline(Shipment $shipment): array
    {
        $timeline = [];
        foreach (ShipmentStatus::cases() as $status) {
            $timestamp = $shipment->getAttribute($status->timestampColumn());
            if ($timestamp instanceof CarbonInterface) {
                $timeline[] = [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'occurred_at' => $timestamp->toIso8601String(),
                ];
            }
        }

        usort($timeline, fn (array $first, array $second): int => strtotime($first['occurred_at']) <=> strtotime($second['occurred_at']));

        return $timeline;
    }
}
