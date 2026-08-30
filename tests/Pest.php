<?php

use Zain\RoutePermissions\Models\Permission;
use Zain\RoutePermissions\Models\Role;
use Zain\RoutePermissions\Tests\TestCase;
use Zain\RoutePermissions\Tests\User;

uses(TestCase::class)->in('Feature');

function makeUser(string $name = 'Test'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.test']);
}

/**
 * @param  string|array<int, string>  $names
 * @return array<int, Permission>
 */
function makePermissions(string|array $names): array
{
    return array_map(
        fn (string $name) => Permission::create(['name' => $name]),
        (array) $names
    );
}

function makeRole(string $name, array $permissions = []): Role
{
    $role = Role::create(['name' => $name]);

    if ($permissions !== []) {
        $role->grant($permissions);
    }

    return $role;
}
