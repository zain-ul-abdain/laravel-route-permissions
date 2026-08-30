<?php

namespace Zain\RoutePermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string|null $description
 * @property bool $from_route
 */
class Permission extends Model
{
    protected $fillable = ['name', 'label', 'description', 'from_route'];

    protected $casts = [
        'from_route' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('route-permissions.tables.permissions', 'permissions');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            config('route-permissions.tables.permission_role', 'permission_role')
        );
    }

    /**
     * The user model is resolved from config rather than hardcoded. The
     * previous version referenced an unqualified User::class from inside the
     * package namespace, which resolved to a non-existent class and made this
     * relationship throw the moment anyone touched it.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('route-permissions.user_model'),
            config('route-permissions.tables.permission_user', 'permission_user')
        );
    }
}
