<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case AwaitingPreparation = 'awaiting_preparation';
    case Preparing = 'preparing';
    case ReadyForDispatch = 'ready_for_dispatch';
    case HandedToLbc = 'handed_to_lbc';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPreparation => 'Awaiting preparation',
            self::Preparing => 'Preparing for shipment',
            self::ReadyForDispatch => 'Ready for dispatch',
            self::HandedToLbc => 'Handed to LBC',
            self::InTransit => 'In transit',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::AwaitingPreparation => self::Preparing,
            self::Preparing => self::ReadyForDispatch,
            self::ReadyForDispatch => self::HandedToLbc,
            self::HandedToLbc => self::InTransit,
            self::InTransit => self::OutForDelivery,
            self::OutForDelivery => self::Delivered,
            self::Delivered, self::Cancelled => null,
        };
    }

    public function timestampColumn(): string
    {
        return match ($this) {
            self::AwaitingPreparation => 'created_at',
            self::Preparing => 'preparing_at',
            self::ReadyForDispatch => 'ready_for_dispatch_at',
            self::HandedToLbc => 'handed_to_carrier_at',
            self::InTransit => 'in_transit_at',
            self::OutForDelivery => 'out_for_delivery_at',
            self::Delivered => 'delivered_at',
            self::Cancelled => 'cancelled_at',
        };
    }
}
