<?php

namespace App\Services;

use App\Enums\AppHealth;
use App\Enums\SlaStatus;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use App\Traits\ScopesDeveloperAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardService
{
    use ScopesDeveloperAccess;

    /**
     * Build the complete dashboard snapshot for the authenticated user.
     *
     * @return array{
     *     apps: Collection<int, object{
     *         id: string,
     *         name: string,
     *         domain: string,
     *         lead: ?string,
     *         health: AppHealth,
     *         uptime: float,
     *         severity: array{P1: int, P2: int, P3: int, P4: int},
     *         statuses: array{open: int, in_progress: int, in_review: int, resolved: int},
     *         created: list<int>,
     *         resolved: list<int>,
     *     }>,
     *     userIncidents: Collection<int, object{
     *         id: string,
     *         title: string,
     *         systemName: string,
     *         systemCode: string,
     *         severity: TicketSeverity,
     *         status: TicketStatus,
     *         slaTime: string,
     *         slaStatus: SlaStatus,
     *         createdAt: string,
     *         assignedToMe: bool,
     *     }>,
     *     mttrHours: float,
     *     slaCompliance: float,
     *     resolvedSprint: int,
     * }
     */
    public function snapshot(User $user): array
    {
        $now = CarbonImmutable::now();
        $scopedSystemIds = $this->accessibleSystemIdsFor($user);

        $apps = $this->buildApps($now, $scopedSystemIds);
        $userIncidents = $this->buildUserIncidents($user, $now, $scopedSystemIds);
        $metrics = $this->buildMetrics($now, $scopedSystemIds);

        return [
            'apps' => $apps,
            'userIncidents' => $userIncidents,
            'mttrHours' => $metrics['mttrHours'],
            'slaCompliance' => $metrics['slaCompliance'],
            'resolvedSprint' => $metrics['resolvedSprint'],
        ];
    }

    /**
     * Aggregate per-system metrics without N+1 queries.
     *
     * @param  Collection<int, string>|null  $assignedSystemIds
     * @return Collection<int, object{
     *     id: string,
     *     name: string,
     *     domain: string,
     *     lead: ?string,
     *     health: AppHealth,
     *     uptime: float,
     *     severity: array{P1: int, P2: int, P3: int, P4: int},
     *     statuses: array{open: int, in_progress: int, in_review: int, resolved: int},
     *     created: list<int>,
     *     resolved: list<int>,
     * }>
     */
    private function buildApps(CarbonImmutable $now, ?Collection $assignedSystemIds = null): Collection
    {
        if ($assignedSystemIds !== null && $assignedSystemIds->isEmpty()) {
            return collect();
        }

        $systemsQuery = System::query()
            ->with('lead:id,name')
            ->orderBy('name')
            ->orderBy('id');

        if ($assignedSystemIds !== null) {
            $systemsQuery->whereKey($assignedSystemIds);
        }

        $systems = $systemsQuery->get();

        if ($systems->isEmpty()) {
            return collect();
        }

        $severityQuery = Ticket::query()
            ->active()
            ->toBase()
            ->select('system_id', 'severity')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('severity')
            ->groupBy('system_id', 'severity');

        if ($assignedSystemIds !== null) {
            $severityQuery->whereIn('system_id', $assignedSystemIds);
        }

        $severityRows = $severityQuery->get();

        $severityBySystem = [];

        foreach ($severityRows as $row) {
            $severityBySystem[$row->system_id][$row->severity] = (int) $row->total;
        }

        $statusQuery = Ticket::query()
            ->toBase()
            ->select('system_id', 'status')
            ->selectRaw('COUNT(*) as total')
            ->where('status', '!=', TicketStatus::Duplicate->value)
            ->groupBy('system_id', 'status');

        if ($assignedSystemIds !== null) {
            $statusQuery->whereIn('system_id', $assignedSystemIds);
        }

        $statusRows = $statusQuery->get();

        $statusesBySystem = [];

        foreach ($statusRows as $row) {
            $bucket = $row->status === TicketStatus::Closed->value
                ? TicketStatus::Resolved->value
                : $row->status;

            $statusesBySystem[$row->system_id][$bucket] = ($statusesBySystem[$row->system_id][$bucket] ?? 0) + (int) $row->total;
        }

        $dates = $this->lastSevenDateStrings($now);
        $windowStart = $now->startOfDay()->subDays(6)->toDateTimeString();
        $windowEnd = $now->endOfDay()->toDateTimeString();

        $createdBySystemDate = $this->countDatesBySystem('created_at', $windowStart, $windowEnd, $assignedSystemIds);
        $resolvedBySystemDate = $this->countDatesBySystem('resolved_at', $windowStart, $windowEnd, $assignedSystemIds);

        return $systems->map(function (System $system) use (
            $severityBySystem,
            $statusesBySystem,
            $createdBySystemDate,
            $resolvedBySystemDate,
            $dates,
        ): object {
            $systemId = $system->id;
            $systemSeverities = $severityBySystem[$systemId] ?? [];
            $systemStatuses = $statusesBySystem[$systemId] ?? [];
            $systemCreated = $createdBySystemDate[$systemId] ?? [];
            $systemResolved = $resolvedBySystemDate[$systemId] ?? [];

            $createdSeries = [];
            $resolvedSeries = [];

            foreach ($dates as $date) {
                $createdSeries[] = $systemCreated[$date] ?? 0;
                $resolvedSeries[] = $systemResolved[$date] ?? 0;
            }

            return (object) [
                'id' => $systemId,
                'name' => $system->name,
                'domain' => $system->domain,
                'lead' => $system->lead?->name,
                'health' => $system->health,
                'uptime' => (float) $system->uptime,
                'severity' => [
                    'P1' => $systemSeverities[TicketSeverity::P1->value] ?? 0,
                    'P2' => $systemSeverities[TicketSeverity::P2->value] ?? 0,
                    'P3' => $systemSeverities[TicketSeverity::P3->value] ?? 0,
                    'P4' => $systemSeverities[TicketSeverity::P4->value] ?? 0,
                ],
                'statuses' => [
                    'open' => $systemStatuses[TicketStatus::Open->value] ?? 0,
                    'in_progress' => $systemStatuses[TicketStatus::InProgress->value] ?? 0,
                    'in_review' => $systemStatuses[TicketStatus::InReview->value] ?? 0,
                    'resolved' => $systemStatuses[TicketStatus::Resolved->value] ?? 0,
                ],
                'created' => $createdSeries,
                'resolved' => $resolvedSeries,
            ];
        });
    }

    /**
     * Build ordered list of active classified incidents for systems assigned to the user.
     *
     * @param  Collection<int, string>|null  $assignedSystemIds
     * @return Collection<int, object{
     *     id: string,
     *     title: string,
     *     systemName: string,
     *     systemCode: string,
     *     severity: TicketSeverity,
     *     status: TicketStatus,
     *     slaTime: string,
     *     slaStatus: SlaStatus,
     *     createdAt: string,
     *     assignedToMe: bool,
     * }>
     */
    private function buildUserIncidents(
        User $user,
        CarbonImmutable $now,
        ?Collection $assignedSystemIds = null,
    ): Collection {
        $assignedSystemIds ??= $user->systems()->pluck('systems.id');

        if ($assignedSystemIds->isEmpty()) {
            return collect();
        }

        $tickets = Ticket::query()
            ->with('system:id,name,domain')
            ->whereIn('system_id', $assignedSystemIds)
            ->active()
            ->whereNotNull('severity')
            ->orderByRaw("CASE severity WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 WHEN 'P3' THEN 3 WHEN 'P4' THEN 4 ELSE 5 END")
            ->orderedLatest()
            ->get();

        return $tickets->map(function (Ticket $ticket) use ($now, $user): object {
            $sla = $this->computeSlaDisplay($ticket, $now);

            return (object) [
                'id' => $ticket->code,
                'title' => Str::limit($ticket->description, 60),
                'systemName' => $ticket->system->name,
                'systemCode' => $ticket->system->domain,
                'severity' => $ticket->severity,
                'status' => $ticket->status,
                'slaTime' => $sla['time'],
                'slaStatus' => $sla['status'],
                'createdAt' => $this->formatRelativeTime($ticket->created_at, $now),
                'assignedToMe' => $ticket->assignee_id === $user->id,
            ];
        });
    }

    /**
     * Compute MTTR, SLA compliance percentage, and sprint resolution count (optionally scoped to systems).
     *
     * @param  Collection<int, string>|null  $assignedSystemIds
     * @return array{mttrHours: float, slaCompliance: float, resolvedSprint: int}
     */
    private function buildMetrics(CarbonImmutable $now, ?Collection $assignedSystemIds = null): array
    {
        if ($assignedSystemIds !== null && $assignedSystemIds->isEmpty()) {
            return [
                'mttrHours' => 0.0,
                'slaCompliance' => 100.0,
                'resolvedSprint' => 0,
            ];
        }

        $mttrWindowStart = $now->subDays(30)->toDateTimeString();
        $sprintWindowStart = $now->subDays(14);

        $recentResolvedQuery = Ticket::query()
            ->resolvedOrClosed()
            ->toBase()
            ->select('created_at', 'resolved_at')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $mttrWindowStart);

        if ($assignedSystemIds !== null) {
            $recentResolvedQuery->whereIn('system_id', $assignedSystemIds);
        }

        $recentResolvedRows = $recentResolvedQuery->get();

        $totalResolutionHours = 0.0;
        $mttrCount = 0;
        $resolvedSprint = 0;

        foreach ($recentResolvedRows as $row) {
            $createdAt = CarbonImmutable::parse($row->created_at);
            $resolvedAt = CarbonImmutable::parse($row->resolved_at);

            $hours = max(0.0, $createdAt->diffInSeconds($resolvedAt, false) / 3600);
            $totalResolutionHours += $hours;
            $mttrCount++;

            if ($resolvedAt->greaterThanOrEqualTo($sprintWindowStart)) {
                $resolvedSprint++;
            }
        }

        $mttrHours = $mttrCount > 0 ? round($totalResolutionHours / $mttrCount, 1) : 0.0;

        $complianceQuery = Ticket::query()
            ->resolvedOrClosed()
            ->toBase()
            ->whereNotNull('resolved_at')
            ->whereNotNull('sla_due_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN resolved_at <= sla_due_at THEN 1 ELSE 0 END) as compliant');

        if ($assignedSystemIds !== null) {
            $complianceQuery->whereIn('system_id', $assignedSystemIds);
        }

        $complianceStats = $complianceQuery->first();

        $complianceTotal = (int) ($complianceStats?->total ?? 0);
        $slaCompliance = $complianceTotal > 0
            ? round(((int) $complianceStats->compliant / $complianceTotal) * 100, 1)
            : 100.0;

        return [
            'mttrHours' => $mttrHours,
            'slaCompliance' => $slaCompliance,
            'resolvedSprint' => $resolvedSprint,
        ];
    }

    /**
     * Build ordered date strings for the last 7 days (index 0 = 6 days ago, index 6 = today).
     *
     * @return list<string>
     */
    private function lastSevenDateStrings(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $dates = [];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $dates[] = $today->subDays($daysAgo)->toDateString();
        }

        return $dates;
    }

    /**
     * Count ticket timestamps per system and calendar date within the given range.
     *
     * @param  Collection<int, string>|null  $assignedSystemIds
     * @return array<string, array<string, int>>
     */
    private function countDatesBySystem(
        string $column,
        string $windowStart,
        string $windowEnd,
        ?Collection $assignedSystemIds = null,
    ): array {
        $query = Ticket::query()
            ->toBase()
            ->select('system_id', $column)
            ->where('status', '!=', TicketStatus::Duplicate->value)
            ->whereNotNull($column)
            ->whereBetween($column, [$windowStart, $windowEnd]);

        if ($assignedSystemIds !== null) {
            $query->whereIn('system_id', $assignedSystemIds);
        }

        $rows = $query->get();

        $counts = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->{$column})->toDateString();
            $counts[$row->system_id][$date] = ($counts[$row->system_id][$date] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Compute Spanish SLA remaining time label and severity-aware SLA status.
     *
     * @return array{time: string, status: SlaStatus}
     */
    private function computeSlaDisplay(Ticket $ticket, CarbonImmutable $now): array
    {
        if ($ticket->sla_due_at === null) {
            return [
                'time' => 'Sin plazo',
                'status' => SlaStatus::Normal,
            ];
        }

        $secondsRemaining = $now->diffInSeconds($ticket->sla_due_at, false);

        if ($secondsRemaining <= 0) {
            return [
                'time' => 'Vencido',
                'status' => SlaStatus::Critical,
            ];
        }

        $remainingMinutes = max(1, (int) ceil($secondsRemaining / 60));

        return [
            'time' => $this->formatRemainingMinutes($remainingMinutes),
            'status' => $this->resolveSlaStatus($ticket->severity, $remainingMinutes),
        ];
    }

    /**
     * Determine SLA status using remaining minutes and severity window thresholds.
     */
    private function resolveSlaStatus(?TicketSeverity $severity, int $remainingMinutes): SlaStatus
    {
        if ($remainingMinutes <= 30) {
            return SlaStatus::Critical;
        }

        $windowMinutes = match ($severity) {
            TicketSeverity::P1 => 60,
            TicketSeverity::P2 => 240,
            TicketSeverity::P3 => 1440,
            TicketSeverity::P4 => 4320,
            null => 240,
        };

        $warningThreshold = max(120, (int) ($windowMinutes * 0.25));

        if ($remainingMinutes <= $warningThreshold) {
            return SlaStatus::Warning;
        }

        return SlaStatus::Normal;
    }

    /**
     * Format remaining SLA minutes in concise Spanish.
     */
    private function formatRemainingMinutes(int $remainingMinutes): string
    {
        if ($remainingMinutes < 60) {
            return $remainingMinutes === 1
                ? '1 min restante'
                : "{$remainingMinutes} min restantes";
        }

        if ($remainingMinutes < 1440) {
            $hours = intdiv($remainingMinutes, 60);
            $minutes = $remainingMinutes % 60;

            if ($minutes > 0) {
                return "{$hours} h {$minutes} min restantes";
            }

            return $hours === 1
                ? '1 h restante'
                : "{$hours} h restantes";
        }

        $days = intdiv($remainingMinutes, 1440);
        $hours = intdiv($remainingMinutes % 1440, 60);

        if ($hours > 0) {
            return "{$days} d {$hours} h restantes";
        }

        return $days === 1
            ? '1 d restante'
            : "{$days} d restantes";
    }

    /**
     * Format elapsed time since ticket creation in concise Spanish.
     */
    private function formatRelativeTime(?CarbonInterface $timestamp, CarbonImmutable $now): string
    {
        if ($timestamp === null) {
            return '';
        }

        $elapsedMinutes = max(0, (int) floor($timestamp->diffInMinutes($now, false)));

        if ($elapsedMinutes < 1) {
            return 'Hace menos de 1 min';
        }

        if ($elapsedMinutes < 60) {
            return "Hace {$elapsedMinutes} min";
        }

        if ($elapsedMinutes < 1440) {
            $hours = intdiv($elapsedMinutes, 60);

            return "Hace {$hours} h";
        }

        $days = intdiv($elapsedMinutes, 1440);

        return "Hace {$days} d";
    }
}
