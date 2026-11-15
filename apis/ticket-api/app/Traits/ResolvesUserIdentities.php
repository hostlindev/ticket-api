<?php

namespace App\Traits;

use App\Models\User;

trait ResolvesUserIdentities
{
    /**
     * Resolve a single internal user ID from a public user identifier.
     */
    protected function resolveUserIdByPublicId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        $id = User::query()
            ->where('public_id', $publicId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve internal user IDs for a list of public user identifiers in a single query.
     *
     * @param  array<int, string>  $publicIds
     * @return list<int>
     */
    protected function resolveUserIdsByPublicIds(array $publicIds): array
    {
        $uniquePublicIds = array_values(array_unique(array_filter(
            $publicIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        if ($uniquePublicIds === []) {
            return [];
        }

        /** @var list<int> */
        return User::query()
            ->whereIn('public_id', $uniquePublicIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
