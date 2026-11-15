<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view the ticket list and summary.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Admin,
            UserRole::Agent,
            UserRole::Developer,
        ], true);
    }

    /**
     * Determine whether the user can view a specific ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $this->canAccessTicket($user, $ticket);
    }

    /**
     * Determine whether the user can update a ticket's status.
     */
    public function updateStatus(User $user, Ticket $ticket): bool
    {
        return $this->canAccessTicket($user, $ticket);
    }

    /**
     * Determine whether the user can access a specific ticket based on role and system assignments.
     */
    private function canAccessTicket(User $user, Ticket $ticket): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Agent], true)) {
            return true;
        }

        if ($user->role === UserRole::Developer) {
            return $user->systems()->whereKey($ticket->system_id)->exists();
        }

        return false;
    }
}
