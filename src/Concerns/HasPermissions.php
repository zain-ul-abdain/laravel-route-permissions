<?php

namespace Zain\RoutePermissions\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Zain\RoutePermissions\Models\Permission;
use Zain\RoutePermissions\Models\Role;
use Zain\RoutePermissions\PermissionRegistry;

/**
 * Add to your authenticatable model.
 *
 * Every check is case-insensitive. The previous version was case-sensitive for
 * permissions and case-insensitive for roles, in the same trait, which is the
 * sort of inconsistency that produces an authorization bug nobody can
 * reproduce.
 */
trait HasPermissions
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            config('route-permissions.tables.role_user', 'role_user')
        );
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            config('route-permissions.tables.permission_user', 'permission_user')
        );
    }

    // ---------------------------------------------------------------- reads

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array(
            mb_strtolower($permission),
            array_map(mb_strtolower(...), $this->registry()->permissionsFor($this)),
            true
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    public function hasRole(string $role): bool
    {
        return in_array(
            mb_strtolower($role),
            array_map(mb_strtolower(...), $this->registry()->rolesFor($this)),
            true
        );
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getRoleNames(): array
    {
        return $this->registry()->rolesFor($this);
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionNames(): array
    {
        return $this->registry()->permissionsFor($this);
    }

    public function isSuperAdmin(): bool
    {
        $role = config('route-permissions.super_admin_role');

        if ($role === null) {
            return false;
        }

        return in_array(
            mb_strtolower($role),
            array_map(mb_strtolower(...), $this->registry()->rolesFor($this)),
            true
        );
    }

    // --------------------------------------------------------------- writes

    /**
     * The previous version had no write API at all - you could check a
     * permission but not grant one, which left every consuming app writing raw
     * pivot inserts.
     *
     * @param  string|array<int, string>  $roles
     */
    public function assignRole(string|array $roles): static
    {
        $ids = Role::query()->whereIn('name', (array) $roles)->pluck('id');

        $this->roles()->syncWithoutDetaching($ids);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function removeRole(string|array $roles): static
    {
        $ids = Role::query()->whereIn('name', (array) $roles)->pluck('id');

        $this->roles()->detach($ids);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function syncRoles(string|array $roles): static
    {
        $ids = Role::query()->whereIn('name', (array) $roles)->pluck('id');

        $this->roles()->sync($ids);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|array<int, string>  $permissions
     */
    public function givePermissionTo(string|array $permissions): static
    {
        $ids = Permission::query()->whereIn('name', (array) $permissions)->pluck('id');

        $this->permissions()->syncWithoutDetaching($ids);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|array<int, string>  $permissions
     */
    public function revokePermissionTo(string|array $permissions): static
    {
        $ids = Permission::query()->whereIn('name', (array) $permissions)->pluck('id');

        $this->permissions()->detach($ids);
        $this->forgetCachedPermissions();

        return $this;
    }

    public function forgetCachedPermissions(): void
    {
        $this->registry()->forget($this);
    }

    protected function registry(): PermissionRegistry
    {
        return app(PermissionRegistry::class);
    }
}
