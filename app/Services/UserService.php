<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * List all platform users with their workload counters.
     *
     * @return Collection<int, User>
     */
    public function listWithWorkload(): Collection
    {
        return User::query()
            ->withWorkloadCounts()
            ->get();
    }

    /**
     * Load workload counters on a single platform user.
     */
    public function loadWorkload(User $user): User
    {
        return $user->loadCount(['systems', 'resolvedTickets']);
    }

    /**
     * List all non-admin team members available for system assignment.
     *
     * @return Collection<int, User>
     */
    public function listDevelopers(): Collection
    {
        return User::query()
            ->orderedDevelopers()
            ->get();
    }

    /**
     * Create a new platform user.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    /**
     * Update a platform user's profile, role, or password.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        $newRole = match (true) {
            ! isset($data['role']) => null,
            $data['role'] instanceof UserRole => $data['role'],
            default => UserRole::from((string) $data['role']),
        };

        if ($newRole !== null && $newRole !== UserRole::Admin) {
            $this->ensureNotLastAdmin($user, 'role', 'Debe quedar al menos un administrador.');
        }

        $user->fill($data)->save();

        return $user;
    }

    /**
     * Soft-delete a user while preserving their resolved ticket history.
     */
    public function delete(User $user): void
    {
        $this->ensureNotLastAdmin($user, 'user', 'No puedes eliminar al único administrador.');

        DB::transaction(function () use ($user): void {
            $this->releaseUnfinishedTickets($user);
            $user->systems()->detach();
            $user->ledSystems()->update(['lead_user_id' => null]);
            $user->tokens()->delete();
            $user->delete();
        });
    }

    /**
     * Ensure modifying or removing this user does not leave the platform without an admin.
     *
     * @throws ValidationException
     */
    private function ensureNotLastAdmin(User $user, string $field, string $message): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        $hasOtherAdmin = User::query()
            ->where('role', UserRole::Admin->value)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $hasOtherAdmin) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }
    }

    /**
     * Unassign active tickets while keeping resolved/closed ticket attribution intact.
     */
    private function releaseUnfinishedTickets(User $user): void
    {
        Ticket::query()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->update(['assignee_id' => null]);
    }
}
