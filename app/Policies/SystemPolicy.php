<?php

namespace App\Policies;

use App\Models\System;
use App\Models\User;

class SystemPolicy
{
    /**
     * Determine whether the user can view any systems.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the system.
     */
    public function view(User $user, System $system): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create systems.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the system.
     */
    public function update(User $user, System $system): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the system.
     */
    public function delete(User $user, System $system): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can synchronize developers assigned to the system.
     */
    public function syncDevelopers(User $user, System $system): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user is the project lead authorized to view or rotate the system API key.
     */
    public function manageApiKey(User $user, System $system): bool
    {
        return $system->lead_user_id !== null && $system->lead_user_id === $user->id;
    }
}
