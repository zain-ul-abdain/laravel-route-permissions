<?php

namespace Zain\RoutePermissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zain\RoutePermissions\Exceptions\UnnamedRouteException;

/**
 * Requires the permission matching the current route's name.
 *
 * Three deliberate differences from the previous implementation, each of which
 * was a defect there:
 *
 *  - A guest gets a 401, not a fatal. The old middleware called
 *    $request->user()->hasPermissionTo() with no null check, so any route
 *    carrying it without `auth` in front produced a 500.
 *  - A denial gets a 403. The old API middleware returned a JSON body with no
 *    status argument, so authorization failures came back as HTTP 200 and any
 *    client checking response.ok treated them as success.
 *  - The permission name comes from the route name, not from munging the
 *    controller class name. Class names change on refactor; route names are
 *    deliberate identifiers. Deriving from the class meant renaming a
 *    controller silently orphaned every permission that referenced it.
 */
class EnsureRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $route = $request->route();
        $name = $route?->getName();

        if ($name === null || $name === '') {
            // Failing loudly is the safe choice. Silently allowing would mean
            // any unnamed route bypasses authorization; silently denying would
            // be an unexplained 403 that takes an afternoon to diagnose.
            throw new UnnamedRouteException(
                'Route ['.($route?->uri() ?? 'unknown').'] has no name, so no permission can be derived. '.
                'Name the route, or use the explicit permission:<name> middleware instead.'
            );
        }

        if (! $user->hasPermissionTo($name)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
