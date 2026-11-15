<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * Authenticate a user by credentials and issue a Sanctum personal access token.
     *
     * @return array{accessToken: string, expiresAt: int, user: User}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, bool $remember = false): array
    {
        $user = User::query()
            ->where('email', $email)
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException('Invalid credentials.');
        }

        $expiresAt = $this->resolveTokenExpiration($remember);
        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

        return [
            'accessToken' => $token,
            'expiresAt' => $expiresAt->getTimestampMs(),
            'user' => $this->profile($user),
        ];
    }

    /**
     * Revoke the user's current Sanctum access token.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * Load the authenticated user's assigned systems.
     */
    public function profile(User $user): User
    {
        return $user->load('systems:id,name');
    }

    /**
     * Determine token expiration based on the remember-me preference.
     */
    private function resolveTokenExpiration(bool $remember): CarbonInterface
    {
        return $remember
            ? now()->addDays(30)
            : now()->addHours(8);
    }
}
