<?php

namespace App\Enums;

enum AppHealth: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Alert = 'alert';

    /**
     * Get all enum string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
