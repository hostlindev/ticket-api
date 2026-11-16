<?php

namespace App\Services;

use App\Enums\AppHealth;
use App\Models\System;
use App\Models\User;
use App\Traits\ResolvesUserIdentities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * Create a new monitored system with an assigned project lead and auto-generated API key.
     *
     * @param  array{
     *     name: string,
     *     domain: string,
     *     leadId: string,
     *     health?: AppHealth|string,
     *     uptime?: float|int|string,
     *     developerIds?: array<int, string>,
     * }  $data
     */
    public function create(array $data): System
    {
        $system = DB::transaction(function () use ($data): System {
            $system = System::query()->create([
                'id' => $this->generateUniqueId($data['name']),
                'name' => $data['name'],
                'domain' => $data['domain'],
                'lead_user_id' => $this->resolveUserIdByPublicId($data['leadId']),
                'health' => $data['health'] ?? AppHealth::Operational,
                'uptime' => array_key_exists('uptime', $data) ? (float) $data['uptime'] : 99.90,
            ]);

            if (! empty($data['developerIds'])) {
                $system->developers()->sync($this->resolveUserIdsByPublicIds($data['developerIds']));
            }

            return $system;
        });

        return $this->loadDetails($system);
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
     * List all monitored systems led by the given project coordinator ordered by name.
     *
     * @return Collection<int, System>
     */
    public function listLedBy(User $user): Collection
    {
        return System::query()
            ->where('lead_user_id', $user->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Generate or rotate the integration API key for the given monitored system.
     */
    public function rotateApiKey(System $system): System
    {
        DB::transaction(function () use ($system): void {
            do {
                $newKey = System::generateApiKey();
            } while ($newKey === $system->api_key || System::withTrashed()->where('api_key', $newKey)->exists());

            $system->forceFill(['api_key' => $newKey])->save();
        });

        return $system->refresh();
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

    /**
     * Generate a unique `app-*` identifier derived from the system name.
     */
    private function generateUniqueId(string $name): string
    {
        $slug = Str::slug($name);
        $baseId = 'app-'.($slug !== '' ? $slug : Str::lower(Str::random(8)));
        $candidate = $baseId;

        while (System::withTrashed()->where('id', $candidate)->exists()) {
            $candidate = $baseId.'-'.Str::lower(Str::random(4));
        }

        return $candidate;
    }
}
