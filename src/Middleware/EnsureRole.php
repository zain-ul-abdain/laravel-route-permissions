<?php

namespace Zain\RoutePermissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zain\RoutePermissions\Middleware\Concerns\Authorizes;

/**
 * Explicit role check — ANY of the listed roles.
 *
 *     ->middleware('role:admin,editor')
 *     ->middleware('role:admin|editor')
 */
class EnsureRole
{
    use Authorizes;

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            $this->denyUnauthenticated($request);
        }

        $roles = $this->names($roles);

        if ($roles === []) {
            abort(500, 'The role middleware requires at least one role name.');
        }

        if (! $user->hasAnyRole($roles)) {
            return $this->denyForbidden($request);
        }

        return $next($request);
    }
}
