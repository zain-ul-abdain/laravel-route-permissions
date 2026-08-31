<?php

namespace Zain\RoutePermissions\Middleware\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shared behaviour for the authorization middleware.
 *
 * Every decision here came from looking at how role middleware actually gets
 * written in production rather than from what is tidiest in a package.
 */
trait Authorizes
{
    /**
     * Resolve the user through the configured guard.
     *
     * Applications with separate admin and customer guards cannot rely on the
     * default one, and threading a guard through middleware parameters makes
     * the route syntax ambiguous once you also accept a list of role names.
     * Configuration is the less surprising place for it.
     */
    protected function resolveUser(Request $request): mixed
    {
        $guard = config('route-permissions.guard');

        return $guard === null
            ? $request->user()
            : Auth::guard($guard)->user();
    }

    /**
     * Accept both separators.
     *
     * Laravel and Spatie conventions use a pipe for "any of these"
     * (role:admin|editor), while variadic middleware parameters arrive
     * comma-separated (role:admin,editor). People write both; neither should
     * silently fail to match.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    protected function names(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            foreach (explode('|', $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    protected function denyUnauthenticated(Request $request): never
    {
        abort(401, 'Unauthenticated.');
    }

    /**
     * A bare 403 is right for an API and wrong for a browser — a user who
     * followed a link to something they cannot see should land somewhere that
     * explains it, not on an error page. When a redirect route is configured
     * it is used for requests that aren't expecting JSON.
     */
    protected function denyForbidden(Request $request): mixed
    {
        $redirect = config('route-permissions.redirect_to');

        if ($redirect !== null && ! $request->expectsJson()) {
            return redirect()->route($redirect);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
