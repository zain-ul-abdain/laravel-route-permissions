<?php

namespace Zain\RoutePermissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit permission check: permission:posts.edit
 *
 * Multiple permissions are treated as ANY, matching Laravel's own convention
 * for the `can` and `role` style middleware:
 *
 *     ->middleware('permission:posts.edit,posts.publish')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if ($permissions === []) {
            abort(500, 'The permission middleware requires at least one permission name.');
        }

        if (! $user->hasAnyPermission($permissions)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
