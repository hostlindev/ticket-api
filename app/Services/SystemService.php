<?php

namespace App\Services;

use App\Enums\AppHealth;
use App\Models\System;
use App\Traits\ResolvesUserIdentities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SystemService
{
    use ResolvesUserIdentities;

    /**
     * List all monitored systems with their assigned developers ordered by name.
     *
     * @return Collection<int, System>
     */
    public function listForAssignments(): Collection
    {
        return System::query()
            ->withAssignmentDevelopers()
            ->get();
    }

    /**
     * Load technical lead and assigned developers on the given system.
     */
    public function loadDetails(System $system): System
    {
        return $system->load([
            'lead:id,public_id,name,email,specialty',
            'developers:id,public_id,name,email,specialty',
        ]);
    }

    /**
     * Update a monitored system and reload its lead and assigned developers.
     *
     * @param  array{
     *     name?: string,
     *     domain?: string,
     *     leadId?: string|null,
     *     health?: AppHealth|string,
     *     uptime?: float|int|string,
     * }  $data
     */
    public function update(System $system, array $data): System
    {
        DB::transaction(function () use ($system, $data): void {
            $attributes = $this->buildUpdatePayload($data);

            if ($attributes !== []) {
                $system->update($attributes);
            }
        });

        return $this->loadDetails($system);
    }

    /**
     * Soft-delete a monitored system and cascade dependent cleanups.
     */
    public function delete(System $system): void
    {
        DB::transaction(function () use ($system): void {
            $system->delete();
        });
    }

    /**
     * Synchronize the assigned developers for a monitored system.
     *
     * @param  array<int, string>  $developerPublicIds
     */
    public function syncDevelopers(System $system, array $developerPublicIds): System
    {
        DB::transaction(function () use ($system, $developerPublicIds): void {
            $userIds = $this->resolveUserIdsByPublicIds($developerPublicIds);
            $system->developers()->sync($userIds);
        });

        return $system->load('developers:id,public_id,name,email,specialty');
    }

    /**
     * Build the normalized column payload for updating a system.
     *
     * @param  array{
     *     name?: string,
     *     domain?: string,
     *     leadId?: string|null,
     *     health?: AppHealth|string,
     *     uptime?: float|int|string,
     * }  $data
     * @return array<string, mixed>
     */
    private function buildUpdatePayload(array $data): array
    {
        $attributes = Arr::only($data, ['name', 'domain', 'health']);

        if (array_key_exists('uptime', $data)) {
            $attributes['uptime'] = (float) $data['uptime'];
        }

        if (array_key_exists('leadId', $data)) {
            $leadId = $data['leadId'];
            $attributes['lead_user_id'] = $this->resolveUserIdByPublicId(
                is_string($leadId) ? $leadId : null,
            );
        }

        return $attributes;
    }
}
