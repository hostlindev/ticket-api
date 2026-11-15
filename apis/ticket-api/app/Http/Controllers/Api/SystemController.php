<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Systems\SyncSystemDevelopersRequest;
use App\Http\Requests\Systems\UpdateSystemRequest;
use App\Http\Resources\SystemAssignmentResource;
use App\Http\Resources\SystemResource;
use App\Models\System;
use App\Services\SystemService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SystemController extends Controller
{
    public function __construct(private readonly SystemService $systemService) {}

    /**
     * List all monitored systems with their assigned developers.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', System::class);

        return SystemAssignmentResource::collection($this->systemService->listForAssignments());
    }

    /**
     * Display a single monitored system with its lead and assigned developers.
     */
    public function show(System $system): SystemResource
    {
        Gate::authorize('view', $system);

        return new SystemResource($this->systemService->loadDetails($system));
    }

    /**
     * Update a monitored system's attributes.
     */
    public function update(UpdateSystemRequest $request, System $system): SystemResource
    {
        Gate::authorize('update', $system);

        return new SystemResource($this->systemService->update($system, $request->validated()));
    }

    /**
     * Soft-delete a monitored system and cascade dependent cleanups.
     */
    public function destroy(System $system): Response
    {
        Gate::authorize('delete', $system);
        $this->systemService->delete($system);

        return response()->noContent();
    }

    /**
     * Synchronize the developers assigned to the given system.
     */
    public function syncDevelopers(SyncSystemDevelopersRequest $request, System $system): SystemAssignmentResource
    {
        Gate::authorize('syncDevelopers', $system);

        /** @var array<int, string> $developerIds */
        $developerIds = $request->validated('developerIds');

        return new SystemAssignmentResource($this->systemService->syncDevelopers($system, $developerIds));
    }
}
