<?php

namespace App\Enums;

enum TicketSeverity: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';

    /**
     * Get all enum string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Determine heuristic ticket severity from the reported issue type.
     */
    public static function fromIssueType(IssueType $issueType): self
    {
        return match ($issueType) {
            IssueType::BlocksFlow => self::P1,
            IssueType::NotWorking => self::P2,
            IssueType::WrongInfo, IssueType::Slow => self::P3,
            IssueType::Visual, IssueType::Other => self::P4,
        };
    }
}
