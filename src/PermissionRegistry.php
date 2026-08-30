<?php

namespace Zain\RoutePermissions;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Zain\RoutePermissions\Models\Permission;

/**
 * Resolves and caches what a user is allowed to do.
 *
 * The previous version answered this by looping the user's roles and lazily
 * loading each role's permissions - an N+1 on every request that carried the
 * middleware. Here the whole effective set is resolved in two queries and
 * cached per user, with explicit invalidation whenever a grant changes.
 */
class PermissionRegistry
{
    /** @var array<string, array<int, string>> in-request memoisation */
    protected array $memo = [];

    /**
     * Every permission name the user holds, whether through a role or granted
     * directly.
     *
     * @return array<int, string>
     */
    public function permissionsFor(Model $user): array
    {
        $key = $this->userKey($user);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (! $this->cachingEnabled()) {
            return $this->memo[$key] = $this->resolve($user);
        }

        $ttl = config('route-permissions.cache.ttl');

        $resolved = $ttl === null
            ? $this->cache()->rememberForever($key, fn () => $this->resolve($user))
            : $this->cache()->remember($key, (int) $ttl, fn () => $this->resolve($user));

        return $this->memo[$key] = $resolved;
    }

    /**
     * @return array<int, string>
     */
    public function rolesFor(Model $user): array
    {
        $key = $this->userKey($user).':roles';

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $resolve = fn () => $user->roles()->pluck(
            config('route-permissions.tables.roles', 'roles').'.name'
        )->all();

        if (! $this->cachingEnabled()) {
            return $this->memo[$key] = $resolve();
        }

        $ttl = config('route-permissions.cache.ttl');

        $resolved = $ttl === null
            ? $this->cache()->rememberForever($key, $resolve)
            : $this->cache()->remember($key, (int) $ttl, $resolve);

        return $this->memo[$key] = $resolved;
    }

    /**
     * Every permission name that exists. Used by the Passport scope sync and
     * by the sync command's diff.
     *
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        $key = $this->prefix().':all';

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $resolve = fn () => Permission::query()->orderBy('name')->pluck('name')->all();

        if (! $this->cachingEnabled()) {
            return $this->memo[$key] = $resolve();
        }

        return $this->memo[$key] = $this->cache()->remember($key, 3600, $resolve);
    }

    public function forget(Model $user): void
    {
        $key = $this->userKey($user);

        unset($this->memo[$key], $this->memo[$key.':roles']);

        if ($this->cachingEnabled()) {
            $this->cache()->forget($key);
            $this->cache()->forget($key.':roles');
        }
    }

    /**
     * Called when the permission catalogue itself changes - a new permission
     * created, or a role's grants edited. Per-user entries are left to expire
     * on their own TTL unless the caller forgets them explicitly, because
     * there is no portable way to enumerate keys across cache drivers.
     */
    public function flushCatalogue(): void
    {
        $this->memo = [];

        if ($this->cachingEnabled()) {
            $this->cache()->forget($this->prefix().':all');
        }
    }

    /**
     * Two queries, not N+1: direct grants, and grants inherited through roles.
     *
     * @return array<int, string>
     */
    protected function resolve(Model $user): array
    {
        $tables = config('route-permissions.tables');
        $permissions = $tables['permissions'] ?? 'permissions';

        $direct = $user->permissions()->pluck($permissions.'.name')->all();

        $viaRoles = Permission::query()
            ->select($permissions.'.name')
            ->join(
                $tables['permission_role'] ?? 'permission_role',
                $permissions.'.id',
                '=',
                ($tables['permission_role'] ?? 'permission_role').'.permission_id'
            )
            ->join(
                $tables['role_user'] ?? 'role_user',
                ($tables['permission_role'] ?? 'permission_role').'.role_id',
                '=',
                ($tables['role_user'] ?? 'role_user').'.role_id'
            )
            ->where(($tables['role_user'] ?? 'role_user').'.user_id', $user->getKey())
            ->pluck($permissions.'.name')
            ->all();

        return array_values(array_unique([...$direct, ...$viaRoles]));
    }

    protected function userKey(Model $user): string
    {
        return $this->prefix().':user:'.$user->getKey();
    }

    protected function prefix(): string
    {
        return (string) config('route-permissions.cache.prefix', 'route-permissions');
    }

    protected function cachingEnabled(): bool
    {
        return (bool) config('route-permissions.cache.enabled', true)
            && config('route-permissions.cache.ttl') !== 0;
    }

    protected function cache(): CacheRepository
    {
        return Cache::store(config('route-permissions.cache.store'));
    }
}
