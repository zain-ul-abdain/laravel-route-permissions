<?php

return [

    /*
     * The application's authenticatable model. Never hardcode this in the
     * package - the previous version referenced an unqualified User::class
     * from inside the package namespace, which resolved to a class that did
     * not exist and broke both relationships silently.
     */
    'user_model' => env('AUTH_MODEL', 'App\\Models\\User'),

    'tables' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'role_user' => 'role_user',
        'permission_role' => 'permission_role',
        'permission_user' => 'permission_user',
    ],

    /*
     * Resolved roles and permissions are cached per user, because the check
     * runs on every request that carries the middleware. Set ttl to null to
     * cache forever and rely purely on invalidation, or 0 to disable caching.
     */
    'cache' => [
        'enabled' => true,
        'store' => null,          // null = default cache store
        'ttl' => 3600,            // seconds
        'prefix' => 'route-permissions',
    ],

    /*
     * Route scanning. `permissions:sync` walks the registered routes and
     * derives one permission per named route.
     *
     * Route names are used rather than controller class names deliberately:
     * a route name is a stable, intentional identifier, whereas a class name
     * changes on any refactor - which would silently orphan every permission
     * record that referenced it.
     */
    'scan' => [
        // Only routes whose name matches one of these patterns are scanned.
        'include' => ['*'],

        // Patterns excluded even when they match `include`.
        'exclude' => [
            'login', 'logout', 'register', 'password.*', 'verification.*',
            'horizon.*', 'telescope.*', 'sanctum.*', 'passport.*', 'ignition.*',
            'livewire.*', 'debugbar.*',
        ],

        // Middleware groups to restrict scanning to. Empty = no restriction.
        'middleware' => [],
    ],

    /*
     * Optional Laravel Passport integration.
     *
     * When enabled, permission names are registered as OAuth token scopes.
     * This is off by default and guarded by a class_exists check, so the
     * package has no hard dependency on Passport.
     */
    'passport' => [
        'sync_scopes' => false,
    ],

    /*
     * Register a Gate::before hook so $user->can('posts.edit') and
     * @can('posts.edit') resolve against this package without any further
     * wiring. Disable if you want to keep Gate definitions explicit.
     */
    'register_gate' => true,

    /*
     * A role whose holders bypass every permission check. Set to null to
     * disable - there is then no way to short-circuit authorization, which
     * some teams prefer.
     */
    'super_admin_role' => null,

];
