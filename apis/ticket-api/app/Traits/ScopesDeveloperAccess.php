<?php

namespace App\Traits;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

trait ScopesDeveloperAccess
{
    /**
     * Resolve accessible system IDs for developers, or null for admins and agents.
     *
     * @return Collection<int, string>|null
     */
    protected function accessibleSystemIdsFor(User $user): ?Collection
    {
        if ($user->role !== UserRole::Developer) {
            return null;
        }

        return $user->accessibleSystemIds();
    }
}
