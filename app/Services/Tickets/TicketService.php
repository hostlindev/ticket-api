<?php

namespace App\Services\Tickets;

use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use App\Modules\Helpdesk\Services\TicketTriageService;
use App\Services\Tickets\Screenshots\ScreenshotStorageContext;
use App\Traits\ScopesDeveloperAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketService
{
    use ScopesDeveloperAccess;

    public function __construct(
        private TicketSlaService $slaService,
        private ScreenshotStorageContext $screenshotStorage,
        private TicketTriageService $triageService,
    ) {}

    /**
     * Paginate tickets visible to the given user with optional filters.
     *
     * @param  array{
     *     page?: int|string|null,
     *     pageSize?: int|string|null,
     *     search?: ?string,
     *     severity?: TicketSeverity|string|null,
     *     status?: TicketStatus|string|null,
     *     system?: ?string,
     *     issueType?: IssueType|string|null,
     * }  $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);

        return Ticket::query()
            ->withResourceRelations()
            ->visibleTo($user)
            ->filter($filters)
            ->orderedLatest()
            ->paginate(perPage: $pageSize, page: $page);
    }

    /**
     * Return aggregate ticket KPI counts and system names that have tickets for the user.
     *
     * @return array{
     *     total: int,
     *     criticalP1: int,
     *     lowPriority: int,
     *     inProgress: int,
     *     resolved: int,
     *     systems: list<string>,
     * }
     */
    public function summaryForUser(User $user): array
    {
        $assignedSystemIds = $this->accessibleSystemIdsFor($user);
        $counts = $this->buildSummaryCounts($assignedSystemIds);

        $systems = System::query()
            ->havingTickets()
            ->when(
                $assignedSystemIds !== null,
                fn (Builder $builder): Builder => $builder->whereKey($assignedSystemIds),
            )
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return [
            'total' => (int) ($counts?->total ?? 0),
            'criticalP1' => (int) ($counts?->critical_p1 ?? 0),
            'lowPriority' => (int) ($counts?->low_priority ?? 0),
            'inProgress' => (int) ($counts?->in_progress ?? 0),
            'resolved' => (int) ($counts?->resolved ?? 0),
            'systems' => $systems,
        ];
    }

    /**
     * Eager load the minimal relationship columns required by TicketResource.
     */
    public function loadDetails(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'system:id,name,domain',
            'assignee:id,public_id,name,email,role,specialty',
            'resolvedBy:id,public_id,name,email,role,specialty,deleted_at',
        ]);
    }

    /**
     * Update a ticket's status and synchronize its resolved_at and resolved_by_id fields.
     */
    public function updateStatus(Ticket $ticket, TicketStatus $status, ?User $actor = null): Ticket
    {
        $wasResolved = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);
        $isResolved = in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true);

        $ticket->status = $status;

        // Flujo: Abierto (sin dueño) -> En revisión (el desarrollador se la asigna) -> Resuelto.
        if ($status === TicketStatus::InReview && $ticket->assignee_id === null && $actor !== null) {
            $ticket->assignee_id = $actor->id;
        } elseif ($status === TicketStatus::Open) {
            $ticket->assignee_id = null;
        }

        if ($isResolved) {
            if (! $wasResolved || $ticket->resolved_at === null) {
                $ticket->resolved_at = now();
                $ticket->resolved_by_id = $actor?->id ?? $ticket->resolved_by_id;
            }
        } else {
            $ticket->resolved_at = null;
            $ticket->resolved_by_id = null;
        }

        $ticket->save();

        return $this->loadDetails($ticket);
    }

    /**
     * Create a new ticket from the embedded widget payload.
     *
     * @param  array{
     *     description: string,
     *     issueType: string,
     *     reporterName: string,
     *     reporterEmail: string,
     *     reporterExternalId?: string|null,
     *     systemDomain?: string|null,
     *     browser?: string|null,
     *     screenshot?: UploadedFile|string|null,
     * }  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function ingestFromWidget(
        array $data,
        ?string $systemKey,
        string $ipAddress,
        ?string $userAgent = null,
    ): Ticket {
        $system = $this->resolveWidgetSystem($systemKey, $data['systemDomain'] ?? null);
        $issueType = IssueType::from($data['issueType']);
        $severity = TicketSeverity::fromIssueType($issueType);
        $slaDueAt = $this->slaService->dueAt($severity);
        $screenshotPath = $this->screenshotStorage->store($data['screenshot'] ?? null);
        $browser = $this->resolveBrowser($data['browser'] ?? null, $userAgent);
        $normalizedIp = $ipAddress !== '' ? Str::limit($ipAddress, 45, '') : '0.0.0.0';

        $reporterSnapshot = $this->resolveReporterSnapshot($data);

        $ticket = DB::transaction(function () use (
            $data,
            $system,
            $reporterSnapshot,
            $issueType,
            $severity,
            $slaDueAt,
            $screenshotPath,
            $browser,
            $normalizedIp,
        ): Ticket {
            return Ticket::query()->create([
                'code' => $this->generateNextCode(),
                'system_id' => $system->id,
                'reporter_external_id' => $reporterSnapshot['reporter_external_id'],
                'reporter_name' => $reporterSnapshot['reporter_name'],
                'reporter_email' => $reporterSnapshot['reporter_email'],
                'assignee_id' => null,
                'description' => $data['description'],
                'issue_type' => $issueType,
                'severity' => $severity,
                'status' => TicketStatus::Open,
                'screenshot_path' => $screenshotPath,
                'browser' => $browser,
                'ip_address' => $normalizedIp,
                'sla_due_at' => $slaDueAt,
                'resolved_at' => null,
            ]);
        });

        return $this->loadDetails($ticket);
    }

    /**
     * Ingest, classify, and deduplicate a ticket reported from the Pixibot widget into the core tickets table.
     *
     * @param  array{
     *     system_name?: ?string,
     *     error_type: string,
     *     description: string,
     *     module_route: string,
     *     user_agent?: ?string,
     *     browser?: ?string,
     *     reporter_data: array{id: mixed, name: string, email?: ?string, role?: ?string, extra?: mixed},
     *     telemetry?: ?array<string, mixed>,
     * }  $data
     * @return array{
     *     ticket: Ticket,
     *     parentTicket: ?Ticket,
     *     layer: string,
     *     isDuplicate: bool,
     *     alreadyResolved: bool,
     * }
     *
     * @throws AuthorizationException
     */
    public function ingestFromPixibot(
        array $data,
        ?string $systemKey,
        ?UploadedFile $screenshot,
        string $ipAddress,
        ?string $requestUserAgent = null,
    ): array {
        $system = $this->resolveSystemByApiKey($systemKey);
        $telemetry = $data['telemetry'] ?? [];

        $normalizedRoute = $this->triageService->normalizeRoute($data['module_route']);
        $layer = $this->triageService->detectLayer($telemetry);
        $issueType = $this->mapPixibotErrorType($data['error_type']);
        $severity = TicketSeverity::fromIssueType($issueType);
        $slaDueAt = $this->slaService->dueAt($severity);

        $browserHint = $data['browser'] ?? (is_string($telemetry['browser'] ?? null) ? $telemetry['browser'] : null);
        $userAgent = $data['user_agent'] ?? $requestUserAgent;
        $resolvedBrowser = $this->resolveBrowser($browserHint, $userAgent);
        $normalizedIp = $ipAddress !== '' ? Str::limit($ipAddress, 45, '') : '0.0.0.0';

        $reporterData = $data['reporter_data'];
        $externalId = trim((string) ($reporterData['id'] ?? ''));
        $email = isset($reporterData['email']) && trim((string) $reporterData['email']) !== ''
            ? trim((string) $reporterData['email'])
            : ($externalId !== '' ? "{$externalId}@pixibot.local" : 'pixibot@ticket.local');

        return DB::transaction(function () use (
            $data,
            $system,
            $telemetry,
            $normalizedRoute,
            $layer,
            $issueType,
            $severity,
            $slaDueAt,
            $resolvedBrowser,
            $userAgent,
            $normalizedIp,
            $reporterData,
            $externalId,
            $email,
            $screenshot,
        ): array {
            $fingerprint = $this->triageService->generateFingerprint(
                $system->id,
                $normalizedRoute,
                $data['error_type'],
                $telemetry,
            );

            $parentTicket = $this->triageService->findExistingParent($system->id, $fingerprint);
            $isDuplicate = $parentTicket !== null;
            $alreadyResolved = $parentTicket?->status === TicketStatus::Resolved;

            $reporterSnapshot = $this->resolveReporterSnapshot([
                'reporterExternalId' => $externalId !== '' ? $externalId : null,
                'reporterName' => (string) $reporterData['name'],
                'reporterEmail' => $email,
            ]);

            $ticket = Ticket::query()->create([
                'code' => $this->generateNextCode(),
                'system_id' => $system->id,
                'parent_ticket_id' => $parentTicket?->id,
                'reporter_external_id' => $reporterSnapshot['reporter_external_id'],
                'reporter_name' => $reporterSnapshot['reporter_name'],
                'reporter_email' => $reporterSnapshot['reporter_email'],
                'assignee_id' => null,
                'description' => $data['description'],
                'module_route' => $normalizedRoute,
                'issue_type' => $issueType,
                'severity' => $severity,
                'status' => $isDuplicate ? TicketStatus::Duplicate : TicketStatus::Open,
                'occurrences_count' => 1,
                'screenshot_path' => null,
                'browser' => $resolvedBrowser,
                'ip_address' => $normalizedIp,
                'user_agent' => $userAgent,
                'reporter_data' => $reporterData,
                'telemetry' => $telemetry,
                'layer' => $layer,
                'fingerprint' => $fingerprint,
                'sla_due_at' => $isDuplicate ? null : $slaDueAt,
                'resolved_at' => null,
            ]);

            if ($isDuplicate) {
                $parentTicket->increment('occurrences_count');
            }

            if ($screenshot !== null) {
                $path = $screenshot->store("tickets/{$ticket->id}", 'public');

                if ($path !== false) {
                    $ticket->update(['screenshot_path' => $path]);
                    $ticket->attachments()->create([
                        'file_path' => $path,
                        'file_name' => $screenshot->getClientOriginalName(),
                        'mime_type' => $screenshot->getClientMimeType() ?? 'application/octet-stream',
                    ]);
                }
            }

            return [
                'ticket' => $ticket,
                'parentTicket' => $parentTicket,
                'layer' => $layer,
                'isDuplicate' => $isDuplicate,
                'alreadyResolved' => $alreadyResolved,
            ];
        });
    }

    /**
     * Aggregate summary KPI counts optionally scoped to assigned system IDs.
     *
     * @param  Collection<int, string>|null  $assignedSystemIds
     */
    private function buildSummaryCounts(?Collection $assignedSystemIds): ?object
    {
        return Ticket::query()
            ->where('status', '!=', TicketStatus::Duplicate->value)
            ->when(
                $assignedSystemIds !== null,
                fn (Builder $builder): Builder => $builder->whereIn('system_id', $assignedSystemIds),
            )
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN severity = ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END) as critical_p1',
                [TicketSeverity::P1->value, TicketStatus::Resolved->value, TicketStatus::Closed->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN severity = ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END) as low_priority',
                [TicketSeverity::P4->value, TicketStatus::Resolved->value, TicketStatus::Closed->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as in_progress',
                [TicketStatus::Open->value, TicketStatus::InProgress->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as resolved',
                [TicketStatus::Resolved->value, TicketStatus::Closed->value],
            )
            ->first();
    }

    /**
     * Resolve an active system by its integration API key.
     *
     * @throws AuthorizationException
     */
    public function resolveSystemByApiKey(?string $systemKey): System
    {
        $trimmedKey = $systemKey !== null ? trim($systemKey) : '';

        if ($trimmedKey === '') {
            throw new AuthorizationException('System API key is required.');
        }

        $system = System::query()->where('api_key', $trimmedKey)->first();

        if ($system === null) {
            throw new AuthorizationException('Invalid system API key.');
        }

        return $system;
    }

    /**
     * Resolve the target system from the X-System-Key header or systemDomain payload field.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function resolveWidgetSystem(?string $systemKey, ?string $systemDomain): System
    {
        $trimmedKey = $systemKey !== null ? trim($systemKey) : '';

        if ($trimmedKey !== '') {
            return $this->resolveSystemByApiKey($trimmedKey);
        }

        $normalizedDomain = $systemDomain !== null ? Str::lower(trim($systemDomain)) : '';

        if ($normalizedDomain !== '') {
            $system = System::query()->where('domain', $normalizedDomain)->first();

            if ($system === null) {
                throw ValidationException::withMessages([
                    'systemDomain' => ['The specified system domain could not be found.'],
                ]);
            }

            return $system;
        }

        throw new AuthorizationException('System identification is required.');
    }

    /**
     * Build the inline reporter snapshot fields stored directly on the ticket.
     *
     * @param  array{
     *     reporterName: string,
     *     reporterEmail: string,
     *     reporterExternalId?: string|null,
     * }  $data
     * @return array{
     *     reporter_external_id: string,
     *     reporter_name: string,
     *     reporter_email: string,
     * }
     */
    private function resolveReporterSnapshot(array $data): array
    {
        $externalId = isset($data['reporterExternalId']) ? trim((string) $data['reporterExternalId']) : '';

        return [
            'reporter_external_id' => $externalId !== '' ? $externalId : 'usr_'.Str::lower(Str::random(8)),
            'reporter_name' => $data['reporterName'],
            'reporter_email' => $data['reporterEmail'],
        ];
    }

    /**
     * Generate the next sequential INC-XXXX code.
     */
    private function generateNextCode(): string
    {
        $maxNumber = 1000;

        $codes = Ticket::withTrashed()
            ->where('code', 'like', 'INC-%')
            ->pluck('code');

        foreach ($codes as $code) {
            if (preg_match('/^INC-(\d+)$/', (string) $code, $matches) === 1) {
                $number = (int) $matches[1];

                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        return sprintf('INC-%04d', $maxNumber + 1);
    }

    /**
     * Normalize the browser label from the explicit field or HTTP User-Agent fallback.
     */
    private function resolveBrowser(?string $browser, ?string $userAgent): string
    {
        if ($browser !== null && trim($browser) !== '') {
            return Str::limit(trim($browser), 255, '');
        }

        return Str::limit((string) ($userAgent ?: 'Unknown'), 255, '');
    }

    /**
     * Map Pixibot error types into core IssueType enum cases.
     */
    private function mapPixibotErrorType(string $errorType): IssueType
    {
        return IssueType::tryFrom($errorType) ?? match ($errorType) {
            'bug' => IssueType::NotWorking,
            'lentitud' => IssueType::Slow,
            default => IssueType::Other,
        };
    }
}
