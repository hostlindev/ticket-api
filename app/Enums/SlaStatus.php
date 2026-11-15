<?php

namespace App\Enums;

enum SlaStatus: string
{
    case Normal = 'normal';
    case Warning = 'warning';
    case Critical = 'critical';

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
