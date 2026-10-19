<?php

namespace App\Enums;

enum AppearancePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function resolved(): self
    {
        return $this === self::Dark ? self::Dark : self::Light;
    }
}
