<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'public_id',
    'name',
    'email',
    'password',
    'role',
    'department',
    'specialty',
    'avatar_url',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Developer->value,
    ];

    /**
     * Bootstrap the model and auto-populate public_id when missing.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->public_id)) {
                $user->public_id = 'usr_'.Str::lower(Str::random(8));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Systems assigned to this team member.
     *
     * @return BelongsToMany<System, $this>
     */
    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(System::class, 'system_user', 'user_id', 'system_id')
            ->withTimestamps()
            ->orderBy('systems.name');
    }

    /**
     * Alias for systems assigned to this team member.
     *
     * @return BelongsToMany<System, $this>
     */
    public function assignedSystems(): BelongsToMany
    {
        return $this->systems();
    }

    /**
     * Tickets currently assigned to this user.
     *
     * @return HasMany<Ticket, $this>
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    /**
     * Tickets this user resolved (kept after the user is soft-deleted).
     *
     * @return HasMany<Ticket, $this>
     */
    public function resolvedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'resolved_by_id');
    }

    /**
     * Systems led by this user.
     *
     * @return HasMany<System, $this>
     */
    public function ledSystems(): HasMany
    {
        return $this->hasMany(System::class, 'lead_user_id');
    }

    /**
     * Determine whether the user holds the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Resolve the system IDs accessible to this user, or null for unrestricted roles.
     *
     * @return Collection<int, string>|null
     */
    public function accessibleSystemIds(): ?Collection
    {
        return $this->role === UserRole::Developer
            ? $this->systems()->pluck('systems.id')
            : null;
    }

    /**
     * Eager load assigned system and resolved ticket counts ordered by name.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWithWorkloadCounts(Builder $query): Builder
    {
        return $query->withCount(['systems', 'resolvedTickets'])->orderBy('name');
    }

    /**
     * Select developer assignment fields ordered by name.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeOrderedDevelopers(Builder $query): Builder
    {
        return $query->select(['id', 'public_id', 'name', 'email', 'specialty'])->orderBy('name');
    }
}
