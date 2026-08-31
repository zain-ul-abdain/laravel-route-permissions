# Laravel Route Permissions

**Scaffold your permission catalogue from your named routes, then enforce it.** Roles, direct grants, cached lookups, Gate integration, and optional Passport scope sync.

[![tests](https://github.com/zain-ul-abdain/laravel-route-permissions/actions/workflows/tests.yml/badge.svg)](https://github.com/zain-ul-abdain/laravel-route-permissions/actions)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

```bash
composer require zain-ul-abdain/laravel-route-permissions
php artisan migrate
php artisan permissions:sync
```

---

## The idea

You already declare your routes. You already name them. So the permission catalogue can be *derived* from them instead of hand-maintained in a seeder that drifts out of sync the moment someone adds a controller.

```bash
$ php artisan permissions:sync

  Routes scanned ........... 47
  New permissions .......... 3
  Orphaned ................. 1

  + posts.publish                                                    new
  + posts.archive                                                    new
  + billing.invoices.void                                            new
  ~ posts.legacy-export                            no matching route

  Orphaned permissions left in place. Re-run with --prune to remove them.
```

That diff is the point. New routes surface as new permissions; deleted routes surface as orphans you decide about. Nothing changes silently.

## Why route names, not controller names

A permission has to be anchored to something stable.

Anchoring to the controller class — `PostController@edit` → `post.edit` — looks convenient and fails badly: renaming the controller changes the derived string, which orphans every grant referencing the old one. Authorization quietly breaks during an ordinary refactor, with no error and no diff.

Route names are deliberate identifiers. You choose them, they appear in `route()` calls throughout your app, and changing one is already a conscious act with visible consequences.

So permissions are **written to the database once** and referenced by name. Enforcement is a dictionary lookup, not string munging at request time.

---

## Usage

Add the trait to your user model:

```php
use Zain\RoutePermissions\Concerns\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
}
```

### Enforcing

```php
// Requires the permission matching this route's name — "posts.edit"
Route::put('/posts/{post}', [PostController::class, 'update'])
    ->name('posts.edit')
    ->middleware(['auth', 'route.permission']);

// Or name the permission explicitly. Multiple = ANY.
Route::post('/posts/{post}/publish', ...)
    ->middleware(['auth', 'permission:posts.publish,posts.manage']);

// Roles
Route::get('/admin', ...)->middleware(['auth', 'role:admin']);
```

A guest gets **401**. An authenticated user without the permission gets **403**. A route with no name throws — failing loudly beats a silent allow.

Roles and permissions both accept the pipe form as well, matching the convention you've probably already written: `role:admin|editor` and `role:admin,editor` behave identically.

### Denying a browser politely

A bare 403 is right for an API and poor for a browser — someone who followed a link to something they can't see should land on a page that explains it.

```php
'redirect_to' => 'access.denied',
```

Non-JSON requests then redirect there; JSON requests still get 403, because an API client can't follow a redirect meaningfully.

> **Leave that route unprotected.** If your denial page is itself behind `route.permission`, a denied user redirects to a page that denies them, and you have an infinite loop.

### Separate guards

If admin and customer authentication use different guards, name the one the middleware should resolve through:

```php
'guard' => 'admin',
```

Null — the default — uses the request's normal guard, which is right for most applications.

### Granting

```php
$user->assignRole('editor');
$user->assignRole(['editor', 'reviewer']);
$user->syncRoles('viewer');            // replaces
$user->removeRole('editor');

$user->givePermissionTo('reports.export');
$user->revokePermissionTo('reports.export');

Role::create(['name' => 'editor'])->grant(['posts.edit', 'posts.publish']);
```

### Checking

```php
$user->hasPermissionTo('posts.edit');
$user->hasAnyPermission(['posts.edit', 'posts.publish']);
$user->hasAllPermissions(['posts.edit', 'posts.publish']);

$user->hasRole('editor');
$user->hasAnyRole(['editor', 'admin']);

$user->getRoleNames();
$user->getPermissionNames();
```

All checks are case-insensitive, consistently — permissions and roles behave the same way.

### Gate and Blade

A `Gate::before` hook is registered by default, so this works with no further wiring:

```php
$user->can('posts.edit');

@can('posts.edit') ... @endcan
```

It returns `null` rather than `false` on a miss, so your existing policies and Gate definitions still resolve normally.

```blade
@permission('posts.edit') ... @endpermission
@role('admin') ... @endrole
@anyrole(['admin', 'editor']) ... @endanyrole
```

---

## Performance

Resolving a user's effective permissions takes **two queries** — direct grants, and grants inherited through roles — regardless of how many roles they hold. The result is cached per user and invalidated on every grant or revoke.

```php
'cache' => [
    'enabled' => true,
    'store' => null,     // default store
    'ttl' => 3600,       // null = forever, 0 = disabled
],
```

---

## Passport scopes (optional)

If you use Passport, permission names can be registered as OAuth token scopes:

```php
'passport' => ['sync_scopes' => true],
```

Passport is a `suggest`, not a requirement. The integration is guarded three ways: off by default, skipped entirely if Passport isn't installed, and the database read is deferred until after boot and wrapped — so a fresh install can still run `php artisan migrate` before the tables exist.

---

## Configuration

```bash
php artisan vendor:publish --tag=route-permissions-config
```

Table names, the user model, cache behaviour, a super-admin role, and scan include/exclude patterns are all configurable. Auth scaffolding routes (`login`, `register`, `password.*`) and common package routes (Horizon, Telescope, Sanctum) are excluded from scanning by default.

---

## Why not `spatie/laravel-permission`?

Mostly, use Spatie. It's excellent, it's battle-tested, and it has features this doesn't — teams, guards, wildcard permissions.

This package exists for one thing Spatie deliberately doesn't do: **generating and reconciling the permission catalogue from your routes**, with a diff you can review and prune. If you're hand-maintaining a permission seeder and it keeps drifting, that's the gap this fills. If you're not, Spatie is the better default.

---

## Testing

```bash
composer install
vendor/bin/pest
```

39 tests. **The suite runs against SQLite, PostgreSQL and MySQL** — behaviour differs by engine, and a SQLite-only suite will pass while hiding real failures. With Docker:

```bash
docker compose run --rm test         # sqlite
docker compose run --rm test-pgsql   # postgres
docker compose run --rm test-mysql   # mysql
```

---

## Predecessor

This replaces `zainburfat/rbac` (2022), which is not maintained and **should not be installed**. A full audit of that version is in [`AUDIT.md`](AUDIT.md) — 22 findings, five critical, including a route file referencing a class that could never autoload, a database query in `boot()` that broke `artisan migrate` on a fresh install, authorization failures returning HTTP 200, and an unauthenticated user-registration endpoint silently added to every consuming application.

This is a rewrite against the same idea, not a patch of that code.

---

## Requirements

PHP 8.2+ · Laravel 12 or 13

## License

MIT. Built by **Zain Ul Abdain** — backend engineer working on payments and authorization infrastructure.

[Portfolio](https://zain-ul-abdain.github.io) · [GitHub](https://github.com/zain-ul-abdain) · [LinkedIn](https://linkedin.com/in/zain-ul-abdain)
