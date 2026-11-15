<?php

namespace App\Services\Tickets;

use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TicketSlaService
{
    /**
     * Compute the SLA due timestamp from a severity level and optional base timestamp.
     */
    public function dueAt(?TicketSeverity $severity, ?CarbonInterface $baseTime = null): ?CarbonImmutable
    {
        if ($severity === null) {
            return null;
        }

        $minutes = match ($severity) {
            TicketSeverity::P1 => 60,
            TicketSeverity::P2 => 240,
            TicketSeverity::P3 => 1440,
            TicketSeverity::P4 => 4320,
        };

        $base = $baseTime !== null
            ? CarbonImmutable::instance($baseTime)
            : CarbonImmutable::now();

        return $base->addMinutes($minutes);
    }

    /**
     * Compute the remaining SLA minutes for a ticket, or null when not applicable.
     */
    public function slaMinutesRemaining(Ticket $ticket): ?int
    {
        if (
            $ticket->severity === null
            || $ticket->sla_due_at === null
            || in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)
        ) {
            return null;
        }

        $remainingSeconds = max(0, now()->diffInSeconds($ticket->sla_due_at, false));

        return (int) ceil($remainingSeconds / 60);
    }
}
