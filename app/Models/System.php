<?php

namespace App\Models;

use App\Enums\AppHealth;
use App\Enums\TicketStatus;
use Database\Factories\SystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'id',
    'name',
    'domain',
    'lead_user_id',
    'health',
    'uptime',
    'api_key',
])]
class System extends Model
{
    /** @use HasFactory<SystemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'health' => AppHealth::Operational->value,
        'uptime' => 99.90,
    ];

    /**
     * Bootstrap the model, generate an API key when omitted, and cascade soft-deletes.
     */
    protected static function booted(): void
    {
        static::creating(function (System $system): void {
            if (empty($system->api_key)) {
                $system->api_key = self::generateApiKey();
            }
        });

        static::deleting(function (System $system): void {
            if ($system->isForceDeleting()) {
                return;
            }

            $system->tickets()->delete();
            $system->developers()->detach();
        });
    }

    /**
     * Generate a system integration API key with the `sk_sys_` prefix.
     */
    public static function generateApiKey(): string
    {
        return 'sk_sys_'.Str::random(32);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'health' => AppHealth::class,
            'uptime' => 'float',
        ];
    }

    /**
     * Technical lead responsible for this system.
     *
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /**
     * Developers and agents assigned to this system.
     *
     * @return BelongsToMany<User, $this>
     */
    public function developers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'system_user', 'system_id', 'user_id')
            ->withTimestamps()
            ->orderBy('users.name');
    }

    /**
     * Tickets reported against this system.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'system_id');
    }

    /**
     * Active (unresolved and unclosed) tickets reported against this system.
     *
     * @return HasMany<Ticket, $this>
     */
    public function activeTickets(): HasMany
    {
        return $this->tickets()
            ->whereNotIn('status', [
                TicketStatus::Resolved->value,
                TicketStatus::Closed->value,
                TicketStatus::Duplicate->value,
            ]);
    }

    /**
     * Eager load assigned developer summary columns ordered by system name.
     *
     * @param  Builder<System>  $query
     * @return Builder<System>
     */
    public function scopeWithAssignmentDevelopers(Builder $query): Builder
    {
        return $query->with(['developers:id,public_id,name,email,specialty'])->orderBy('name');
    }

    /**
     * Eager load technical lead and assigned developer summary columns.
     *
     * @param  Builder<System>  $query
     * @return Builder<System>
     */
    public function scopeWithDetails(Builder $query): Builder
    {
        return $query->with([
            'lead:id,public_id,name,email,specialty',
            'developers:id,public_id,name,email,specialty',
        ]);
    }

    /**
     * Limit systems to those that have at least one ticket.
     *
     * @param  Builder<System>  $query
     * @return Builder<System>
     */
    public function scopeHavingTickets(Builder $query): Builder
    {
        return $query->whereHas('tickets');
    }
}
