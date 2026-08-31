<?php

namespace Zain\RoutePermissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zain\RoutePermissions\Exceptions\UnnamedRouteException;
use Zain\RoutePermissions\Middleware\Concerns\Authorizes;

/**
 * Requires the permission matching the current route's name.
 *
 * The permission name comes from the route name, not from munging the
 * controller class name. Class names change on refactor; route names are
 * deliberate identifiers. Deriving from the class means renaming a controller
 * silently orphans every permission that referenced it.
 */
class EnsureRoutePermission
{
    use Authorizes;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            $this->denyUnauthenticated($request);
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
            return $this->denyForbidden($request);
        }

        return $next($request);
    }
}
