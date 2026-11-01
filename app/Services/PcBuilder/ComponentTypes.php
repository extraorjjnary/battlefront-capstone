<?php

namespace App\Services\PcBuilder;

class ComponentTypes
{
    /** @return array<string, array{label: string, group: string, required: bool, multiple: bool, simple: bool}> */
    public static function definitions(): array
    {
        static $definitions;

        if ($definitions === null) {
            $json = file_get_contents(dirname(__DIR__, 3).'/resources/data/pc-builder-components.json');
            if ($json === false) {
                throw new \RuntimeException('PC Builder component definitions are unavailable.');
            }
            $definitions = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }

        return $definitions;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::definitions());
    }

    /** @return list<string> */
    public static function required(): array
    {
        return array_keys(array_filter(self::definitions(), fn (array $type): bool => $type['required']));
    }
}
