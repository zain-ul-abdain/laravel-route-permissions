<?php

namespace Zain\RoutePermissions;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Zain\RoutePermissions\Console\SyncPermissionsCommand;
use Zain\RoutePermissions\Middleware\EnsurePermission;
use Zain\RoutePermissions\Middleware\EnsureRole;
use Zain\RoutePermissions\Middleware\EnsureRoutePermission;

class RoutePermissionsServiceProvider extends ServiceProvider
{
    /**
     * register() binds things into the container and does nothing else.
     *
     * The previous version loaded migrations and routes here, and ran
     * Permission::all() during boot — a database query on every request and
     * every artisan command, which meant `php artisan migrate` failed on a
     * fresh install because the table it queried did not exist yet.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/route-permissions.php', 'route-permissions');

        $this->app->singleton(PermissionRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/route-permissions.php' => config_path('route-permissions.php'),
            ], 'route-permissions-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'route-permissions-migrations');

            $this->commands([SyncPermissionsCommand::class]);
        }

        $this->registerMiddlewareAliases();
        $this->registerBladeDirectives();
        $this->registerGate();
        $this->registerPassportScopes();
    }

    protected function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('route.permission', EnsureRoutePermission::class);
        $router->aliasMiddleware('permission', EnsurePermission::class);
        $router->aliasMiddleware('role', EnsureRole::class);
    }

    /**
     * Lets $user->can('posts.edit') and @can('posts.edit') resolve against this
     * package with no further wiring.
     *
     * Returning null rather than false on a miss is essential: false would be a
     * hard deny that overrides every other Gate definition and policy in the
     * application. Null falls through to normal resolution.
     */
    protected function registerGate(): void
    {
        if (! config('route-permissions.register_gate', true)) {
            return;
        }

        $this->app->booted(function () {
            /** @var Gate $gate */
            $gate = $this->app->make(Gate::class);

            $gate->before(function ($user, string $ability) {
                if (! method_exists($user, 'hasPermissionTo')) {
                    return null;
                }

                return $user->hasPermissionTo($ability) ? true : null;
            });
        });
    }

    protected function registerBladeDirectives(): void
    {
        // @permission('posts.edit') ... @endpermission
        Blade::if('permission', function (string $permission) {
            $user = auth()->user();

            return $user !== null
                && method_exists($user, 'hasPermissionTo')
                && $user->hasPermissionTo($permission);
        });

        // @role('admin') ... @endrole
        Blade::if('role', function (string $role) {
            $user = auth()->user();

            return $user !== null
                && method_exists($user, 'hasRole')
                && $user->hasRole($role);
        });

        // @anyrole(['admin', 'editor']) ... @endanyrole
        Blade::if('anyrole', function (array $roles) {
            $user = auth()->user();

            return $user !== null
                && method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole($roles);
        });
    }

    /**
     * Optional Passport integration.
     *
     * Three guards, all of which the previous version lacked: the feature is
     * off by default, Passport's absence is tolerated, and the database read
     * is deferred until the application has booted and wrapped so that a
     * missing table on a fresh install cannot break `artisan migrate`.
     */
    protected function registerPassportScopes(): void
    {
        if (! config('route-permissions.passport.sync_scopes', false)) {
            return;
        }

        if (! class_exists(Passport::class)) {
            return;
        }

        $this->app->booted(function () {
            try {
                $names = $this->app->make(PermissionRegistry::class)->allPermissionNames();

                if ($names === []) {
                    return;
                }

                Passport::tokensCan(array_combine($names, $names));
            } catch (\Throwable) {
                // The permissions table may not exist yet — a fresh install
                // runs this provider before `migrate`. Scope synchronisation
                // is an optimisation; it is never a reason to break boot.
            }
        });
    }
}
