<?php

namespace App\Http\Middleware;

use BackedEnum;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $userRole = $user->role instanceof BackedEnum
            ? (string) $user->role->value
            : (string) $user->role;

        if ($roles === [] || ! in_array($userRole, $roles, true)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $next($request);
    }
}
