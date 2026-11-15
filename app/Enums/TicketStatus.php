<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Duplicate = 'duplicate';

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
