<?php

namespace App\Models;

use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Traits\EscapesLikeWildcards;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'system_id',
    'parent_ticket_id',
    'reporter_id',
    'assignee_id',
    'resolved_by_id',
    'description',
    'module_route',
    'issue_type',
    'severity',
    'status',
    'occurrences_count',
    'screenshot_path',
    'browser',
    'ip_address',
    'user_agent',
    'reporter_data',
    'telemetry',
    'layer',
    'fingerprint',
    'sla_due_at',
    'resolved_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use EscapesLikeWildcards, HasFactory, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TicketStatus::Open->value,
        'layer' => 'por_definir',
        'occurrences_count' => 1,
    ];

    /**
     * Bootstrap the model and derive severity from issue_type when omitted.
     */
    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            if ($ticket->severity === null && $ticket->issue_type !== null) {
                $issueType = $ticket->issue_type instanceof IssueType
                    ? $ticket->issue_type
                    : IssueType::from((string) $ticket->issue_type);

                $ticket->severity = TicketSeverity::fromIssueType($issueType);
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_type' => IssueType::class,
            'severity' => TicketSeverity::class,
            'status' => TicketStatus::class,
            'reporter_data' => 'array',
            'telemetry' => 'array',
            'occurrences_count' => 'integer',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * System where the issue was reported.
     *
     * @return BelongsTo<System, $this>
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    /**
     * End user who reported the ticket.
     *
     * @return BelongsTo<Reporter, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Reporter::class, 'reporter_id');
    }

    /**
     * Team member who resolved the ticket, even if they were later removed.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id')->withTrashed();
    }

    /**
     * Team member assigned to resolve the ticket (kept for resolved tickets even if later removed).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    /**
     * Master ticket when this ticket was flagged as a duplicate.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'parent_ticket_id');
    }

    /**
     * Duplicate tickets linked to this master ticket.
     *
     * @return HasMany<Ticket, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(Ticket::class, 'parent_ticket_id');
    }

    /**
     * Files attached to this ticket.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Eager load the minimal relationship columns required by TicketResource.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeWithResourceRelations(Builder $query): Builder
    {
        return $query->with([
            'system:id,name,domain',
            'reporter:id,name,email',
            'assignee:id,public_id,name,email,role,specialty',
            'resolvedBy:id,public_id,name,email,role,specialty,deleted_at',
        ]);
    }

    /**
     * Restrict tickets to systems accessible by the given user.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $systemIds = $user->accessibleSystemIds();

        return $query->when(
            $systemIds !== null,
            fn (Builder $builder): Builder => $builder->whereIn('system_id', $systemIds),
        );
    }

    /**
     * Limit tickets to active (unresolved and unclosed) statuses.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            TicketStatus::Resolved->value,
            TicketStatus::Closed->value,
            TicketStatus::Duplicate->value,
        ]);
    }

    /**
     * Limit tickets to resolved or closed statuses.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeResolvedOrClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Resolved->value,
            TicketStatus::Closed->value,
        ]);
    }

    /**
     * Apply search, severity, status, system, and issueType filters.
     *
     * @param  Builder<Ticket>  $query
     * @param  array{
     *     search?: ?string,
     *     severity?: TicketSeverity|string|null,
     *     status?: TicketStatus|string|null,
     *     system?: ?string,
     *     issueType?: IssueType|string|null,
     * }  $filters
     * @return Builder<Ticket>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $system = isset($filters['system']) ? trim((string) $filters['system']) : '';

        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        $this->applyExactFilter($query, 'severity', $filters['severity'] ?? null);
        $this->applyExactFilter($query, 'status', $filters['status'] ?? null);

        if (($filters['status'] ?? null) !== TicketStatus::Duplicate->value) {
            $query->where('status', '!=', TicketStatus::Duplicate->value);
        }

        if ($system !== '' && $system !== 'all') {
            $this->applySystemFilter($query, $system);
        }

        $this->applyExactFilter($query, 'issue_type', $filters['issueType'] ?? null);

        return $query;
    }

    /**
     * Order tickets newest first with a deterministic ID tiebreaker.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOrderedLatest(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Filter tickets matching a search term across code, description, system, and reporter.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySearchFilter(Builder $query, string $search): void
    {
        $like = $this->toLikePattern($search);

        $query->where(function (Builder $builder) use ($like): void {
            $builder->where('code', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('system', fn (Builder $systemQuery): Builder => $systemQuery->where('name', 'like', $like))
                ->orWhereHas('reporter', fn (Builder $reporterQuery): Builder => $reporterQuery
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
        });
    }

    /**
     * Filter tickets belonging to a system matched by name or identifier.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySystemFilter(Builder $query, string $system): void
    {
        $query->whereHas('system', function (Builder $systemQuery) use ($system): void {
            $systemQuery->where('name', $system)
                ->orWhere('id', $system);
        });
    }

    /**
     * Apply an equality filter unless the value is empty or the sentinel "all".
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyExactFilter(Builder $query, string $column, mixed $value): void
    {
        if ($value === null || $value === '' || $value === 'all') {
            return;
        }

        $query->where($column, $value);
    }
}
