<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Systems\StoreSystemRequest;
use App\Http\Requests\Systems\SyncSystemDevelopersRequest;
use App\Http\Requests\Systems\UpdateSystemRequest;
use App\Http\Resources\LedSystemResource;
use App\Http\Resources\SystemApiKeyResource;
use App\Http\Resources\SystemAssignmentResource;
use App\Http\Resources\SystemResource;
use App\Models\System;
use App\Models\User;
use App\Services\SystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Create a new monitored system with an assigned project lead.
     */
    public function store(StoreSystemRequest $request): JsonResponse
    {
        Gate::authorize('create', System::class);

        $system = $this->systemService->create($request->validated());

        return (new SystemResource($system))
            ->response()
            ->setStatusCode(201);
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
     * List all monitored systems coordinated by the authenticated user.
     */
    public function lead(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return LedSystemResource::collection($this->systemService->listLedBy($user));
    }

    /**
     * Display the current API key of a system for its assigned project lead.
     */
    public function showApiKey(System $system): SystemApiKeyResource
    {
        Gate::authorize('manageApiKey', $system);

        return new SystemApiKeyResource($system);
    }

    /**
     * Generate or rotate the API key of a system for its assigned project lead.
     */
    public function rotateApiKey(System $system): SystemApiKeyResource
    {
        Gate::authorize('manageApiKey', $system);

        return new SystemApiKeyResource($this->systemService->rotateApiKey($system));
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
