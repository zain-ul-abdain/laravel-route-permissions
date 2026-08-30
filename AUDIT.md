# Security & Quality Audit — `zainburfat/rbac`

**Audited:** 30 August 2026 · **Code dated:** Nov 2022 – Jan 2023 · **Files reviewed:** 20/20 (complete)

---

## Verdict

**Do not publish this as-is.** Three defects break the consuming application on install, and one silently adds a public unauthenticated user-registration endpoint to any app that requires the package.

The *concept* is sound and worth shipping. The 2022 implementation should be treated as a specification, not a starting point.

---

## CRITICAL — breaks or endangers the consuming app

### C1. Installing the package fatals the host application

`src/routes/api.php` imports `App\Http\Controllers\Api\AuthController`. That class does not exist in this package — the file at `src/Controllers/Api/AuthController.php` declares namespace `App\Http\Controllers\Api`, which does not match its PSR-4 path (`Zainburfat\Rbac\` → `src/`). It can never autoload.

The service provider then loads that route file **unconditionally** in `register()`. So any application that installs this package attempts to resolve a non-existent class on every route resolution.

### C2. A public, unauthenticated registration endpoint is added to every consuming app

```php
Route::post('rbac_register', [AuthController::class, 'register']);
```

`register()` performs **no validation** — no required fields, no email uniqueness, no password rules — and there is no rate limiting. It mass-assigns straight from the request and creates a user.

Installing an authorization package must never open a public account-creation route. This is the single most serious finding.

### C3. A database query runs on every request and every artisan command

```php
public function boot() { ... $this->registerPassportServices(); }
public function registerPassportServices() {
    $permission_all = Permission::all();   // ← queries the DB during boot
```

Consequences: `php artisan migrate` fails on a fresh install because the table doesn't exist yet; package discovery during `composer install` fails for the same reason; and every HTTP request pays a full table scan of `permissions` before routing.

### C4. Authorization failure returns HTTP 200

```php
if (!$request->user()->tokenCan($scope)) {
    return response()->json(['message' => 'unauthenticated']);   // ← status 200
}
```

No status code, so Laravel defaults to 200. Any client checking `response.ok`, `status < 400`, or an HTTP status guard treats a **denied** request as successful. The login endpoint has the same shape — invalid credentials return 200 with `status: false`.

### C5. `php artisan config:cache` is broken

```php
return ['tokensExpireIn' => now()->addDays(10), ...];
```

Config caching serialises via `var_export`. Carbon instances don't round-trip. Config caching is standard in production deployments, so this fails exactly where it matters. These should be integer day counts resolved at use site.

---

## HIGH

### H1. Both middleware fatal instead of denying, when unauthenticated

```php
if (!$request->user()->hasPermissionTo($permission))
```

`$request->user()` is `null` for a guest. Calling a method on null throws — a 500, not a 401. Any route that carries this middleware without `auth` in front of it is a crash, not a denial.

### H2. Token scopes are assigned wrongly — users get one permission instead of all

```php
$permissions = ['-'];
foreach ($user->permissions as $permission) {
    $permissions = $permission->name;   // ← overwrites the array with a string
}
$token = $user->createToken($user->name, [$permissions]);
```

`$permissions` is reassigned rather than appended, so the token carries only the **last** permission. A user with five permissions is authorised for one. Should be `$permissions[] = $permission->name;`.

### H3. Two model relationships reference an unresolvable class

`Permission::users()` and `Role::users()` both use `User::class` with no import. It resolves to `Zainburfat\Rbac\Models\User`, which does not exist. Both relations fatal on use. The user model must be configurable, not hardcoded.

---

## MEDIUM

| # | Finding |
|---|---|
| M1 | **N+1 on every permission check.** `hasPermissionTo()` iterates roles then lazy-loads `$role->permissions` per role — on every request through the middleware. No caching anywhere. |
| M2 | **Permission strings are derived from controller class names.** Renaming a controller silently changes the permission string and orphans every existing permission row. Authorization that breaks on a refactor is a design hazard. |
| M3 | **Closure and invokable routes crash the middleware.** `getActionName()` returns `Closure` (no `@method`), so `$temp[1]` is an undefined array key. |
| M4 | `"minimum-stability": "dev"` — pulls development versions of every dependency into consuming apps. |
| M5 | **No `php` or `illuminate/*` constraint** in `composer.json`. Only `laravel/passport: ^10.4` — Passport is now v13. |
| M6 | `Passport::routes()` was **removed in Passport 11**. This code cannot run on any current Passport. |
| M7 | **Zero tests.** No test directory, no CI, no `require-dev`. |
| M8 | `hasRole()` is case-insensitive; `hasPermissionTo()` is case-sensitive. Same package, opposite semantics. |
| M9 | **No write API.** The trait can check permissions but not grant them — no `assignRole`, `givePermissionTo`, `revokePermissionTo`, `syncRoles`. For an RBAC package that's a functional gap, not a missing convenience. |
| M10 | Pivot tables modelled as `Model` rather than `Pivot`; `HasFactory` used with no factories shipped. |
| M11 | Table and pivot names hardcoded — not configurable. |
| M12 | Blade directives interpolate the raw argument into generated PHP without escaping. |
| M13 | Routes registered with no configurable prefix, middleware group, or opt-out. |
| M14 | `register()`/`boot()` lifecycle inverted — migrations and routes load in `register()`, which should only bind to the container. |

---

## Totals

| Severity | Count |
|---|---|
| Critical | 5 |
| High | 3 |
| Medium | 14 |
| **Total** | **22** |

---

## Recommendation

**Rewrite, don't patch.**

Roughly 60% of the source is either broken (`AuthController`, `routes/api.php`, both `users()` relations) or must be redesigned (the service provider's boot-time query, the middleware's null handling and permission derivation). Patching twenty-two findings across code that also needs to move from Laravel 9 to 13 and Passport 10 to 13 is slower and riskier than rebuilding against the same specification.

**What to keep from the original:**

- The core idea — roles, permissions, direct user permissions, three pivot tables
- The `@permission` / `@role` Blade directives
- Automatic permission generation from controllers as an *optional generator command* — but writing permission names into the database once, not deriving them at request time from class names
- Passport scope integration as an **optional** sub-feature, not a hard dependency

**Material worth salvaging from `custom-rbac`:**

The second repo is not a second version of the package — it's the **Laravel 8 application the package was extracted from** (Nov 2022), with the same RBAC code living inline in `app/`. It does not consume the package.

But the extraction left useful things behind. The app has code the package lacks:

- `app/Http/Requests/StorePermissionRequest.php`, `UpdatePermissionRequest.php`, `StoreRoleRequest.php`, `UpdateRoleRequest.php` — **form-request validation**, entirely absent from the package
- `app/Http/Controllers/PermissionController.php`, `RoleController.php` — CRUD for roles and permissions

So the package is in some respects *less* complete than the application it came from. If the rewrite ships management endpoints, that validation layer is a starting point rather than a blank page.

**What to drop:**

- `AuthController` and the bundled routes entirely. An authorization package should not ship authentication endpoints. This alone removes C1, C2 and one of the C4 cases.
- The hard `laravel/passport` requirement. Make it a `suggest` and guard the integration behind a config flag.

### Before investing the time, a strategic question

`spatie/laravel-permission` owns this space with tens of millions of installs. A new RBAC package starting from zero is unlikely to be adopted on merit alone.

The genuine differentiator here is **automatic permission scaffolding from controllers plus Passport scope synchronisation** — which Spatie deliberately doesn't do. That is a real niche and worth building. But it should be framed as *"permission scaffolding and Passport scopes"*, not *"another RBAC package"*, or it will be compared directly against Spatie and lose.

As a **portfolio artifact** it works regardless: a well-built, well-tested package with a documented security audit of its own prior version is a strong signal, independent of download counts.
