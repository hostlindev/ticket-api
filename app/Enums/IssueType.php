<?php

namespace App\Enums;

enum IssueType: string
{
    case BlocksFlow = 'blocks_flow';
    case NotWorking = 'not_working';
    case WrongInfo = 'wrong_info';
    case Slow = 'slow';
    case Visual = 'visual';
    case Other = 'other';

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
