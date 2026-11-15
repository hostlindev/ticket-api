<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct(private readonly UserService $userService) {}

    /**
     * List platform users with their workload counters.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return ManagedUserResource::collection($this->userService->listWithWorkload());
    }

    /**
     * Display a single platform user with their workload counters.
     */
    public function show(User $user): ManagedUserResource
    {
        Gate::authorize('view', $user);

        return new ManagedUserResource($this->userService->loadWorkload($user));
    }

    /**
     * Create a new platform user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);
        $user = $this->userService->create($request->validated());

        return (new ManagedUserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Update a platform user's profile, role or password.
     */
    public function update(UpdateUserRequest $request, User $user): ManagedUserResource
    {
        Gate::authorize('update', $user);

        return new ManagedUserResource($this->userService->update($user, $request->validated()));
    }

    /**
     * Soft-delete a platform user, preserving the tickets they resolved.
     */
    public function destroy(User $user): Response
    {
        Gate::authorize('delete', $user);
        $this->userService->delete($user);

        return response()->noContent();
    }
}
