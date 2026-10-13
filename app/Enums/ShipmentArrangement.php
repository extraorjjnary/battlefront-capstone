<?php

namespace App\Enums;

enum ShipmentArrangement: string
{
    case ConfiguredRate = 'configured_rate';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ConfiguredRate => 'Configured-rate LBC delivery',
            self::Manual => 'Manually arranged LBC delivery',
        };
    }
}
