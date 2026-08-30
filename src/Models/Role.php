<?php

namespace Zain\RoutePermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string|null $description
 */
class Role extends Model
{
    protected $fillable = ['name', 'label', 'description'];

    public function getTable(): string
    {
        return config('route-permissions.tables.roles', 'roles');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            config('route-permissions.tables.permission_role', 'permission_role')
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('route-permissions.user_model'),
            config('route-permissions.tables.role_user', 'role_user')
        );
    }

    /**
     * Grant permissions by name, creating none - a permission must exist
     * before it can be granted, so a typo fails loudly instead of silently
     * creating a permission nothing will ever check.
     *
     * @param  string|array<int, string>  $names
     */
    public function grant(string|array $names): static
    {
        $ids = Permission::query()
            ->whereIn('name', (array) $names)
            ->pluck('id');

        $this->permissions()->syncWithoutDetaching($ids);

        return $this;
    }

    /**
     * @param  string|array<int, string>  $names
     */
    public function revoke(string|array $names): static
    {
        $ids = Permission::query()
            ->whereIn('name', (array) $names)
            ->pluck('id');

        $this->permissions()->detach($ids);

        return $this;
    }
}
