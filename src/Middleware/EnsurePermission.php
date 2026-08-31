<?php

namespace Zain\RoutePermissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zain\RoutePermissions\Middleware\Concerns\Authorizes;

/**
 * Explicit permission check. Multiple permissions mean ANY, matching Laravel's
 * own convention for `can` and Spatie's for `role`:
 *
 *     ->middleware('permission:posts.edit,posts.publish')
 *     ->middleware('permission:posts.edit|posts.publish')
 */
class EnsurePermission
{
    use Authorizes;

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            $this->denyUnauthenticated($request);
        }

        $permissions = $this->names($permissions);

        if ($permissions === []) {
            abort(500, 'The permission middleware requires at least one permission name.');
        }

        if (! $user->hasAnyPermission($permissions)) {
            return $this->denyForbidden($request);
        }

        return $next($request);
    }
}
