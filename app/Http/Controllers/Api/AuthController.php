<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Authenticate a user and return an access token with user profile.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        return response()->json([
            'data' => [
                'accessToken' => $result['accessToken'],
                'expiresAt' => $result['expiresAt'],
                'user' => new AuthUserResource($result['user']),
            ],
        ]);
    }

    /**
     * Revoke the authenticated user's current access token.
     */
    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user);

        return response()->noContent();
    }

    /**
     * Return the currently authenticated user's profile and assigned systems.
     */
    public function me(Request $request): AuthUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AuthUserResource($this->authService->profile($user));
    }
}
