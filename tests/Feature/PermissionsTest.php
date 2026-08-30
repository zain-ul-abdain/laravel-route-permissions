<?php

use Illuminate\Support\Facades\DB;
use Zain\RoutePermissions\Models\Permission;
use Zain\RoutePermissions\Models\Role;

it('grants a permission through a role', function () {
    makePermissions(['posts.edit', 'posts.delete']);
    $role = makeRole('editor', ['posts.edit']);

    $user = makeUser();
    $user->assignRole('editor');

    expect($user->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($user->hasPermissionTo('posts.delete'))->toBeFalse()
        ->and($user->hasRole('editor'))->toBeTrue()
        ->and($role->permissions)->toHaveCount(1);
});

it('grants a permission directly to a user', function () {
    makePermissions('reports.export');

    $user = makeUser();
    $user->givePermissionTo('reports.export');

    expect($user->hasPermissionTo('reports.export'))->toBeTrue()
        ->and($user->getRoleNames())->toBe([]);
});

it('combines role permissions and direct permissions without duplicates', function () {
    makePermissions(['a', 'b', 'c']);
    makeRole('editor', ['a', 'b']);

    $user = makeUser();
    $user->assignRole('editor')->givePermissionTo(['b', 'c']);

    expect($user->getPermissionNames())->toHaveCount(3)
        ->and($user->getPermissionNames())->toContain('a', 'b', 'c');
});

it('treats permission and role checks case-insensitively', function () {
    // The previous version was case-sensitive for permissions and
    // case-insensitive for roles, in the same trait.
    makePermissions('Posts.Edit');
    makeRole('Admin', ['Posts.Edit']);

    $user = makeUser();
    $user->assignRole('Admin');

    expect($user->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($user->hasPermissionTo('POSTS.EDIT'))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeTrue();
});

it('revokes roles and permissions', function () {
    makePermissions(['a', 'b']);
    makeRole('editor', ['a']);

    $user = makeUser();
    $user->assignRole('editor')->givePermissionTo('b');

    expect($user->hasPermissionTo('a'))->toBeTrue()
        ->and($user->hasPermissionTo('b'))->toBeTrue();

    $user->removeRole('editor')->revokePermissionTo('b');

    expect($user->hasPermissionTo('a'))->toBeFalse()
        ->and($user->hasPermissionTo('b'))->toBeFalse();
});

it('syncs roles, replacing rather than adding', function () {
    makeRole('editor');
    makeRole('viewer');

    $user = makeUser();
    $user->assignRole('editor');
    $user->syncRoles('viewer');

    expect($user->getRoleNames())->toBe(['viewer']);
});

it('is idempotent when the same role is assigned twice', function () {
    makeRole('editor');

    $user = makeUser();
    $user->assignRole('editor');
    $user->assignRole('editor');

    expect(DB::table('role_user')->count())->toBe(1);
});

it('checks any and all permissions', function () {
    makePermissions(['a', 'b', 'c']);
    $user = makeUser();
    $user->givePermissionTo(['a', 'b']);

    expect($user->hasAnyPermission(['a', 'z']))->toBeTrue()
        ->and($user->hasAnyPermission(['y', 'z']))->toBeFalse()
        ->and($user->hasAllPermissions(['a', 'b']))->toBeTrue()
        ->and($user->hasAllPermissions(['a', 'c']))->toBeFalse();
});

it('lets a super admin role bypass every check', function () {
    config()->set('route-permissions.super_admin_role', 'god');
    makeRole('god');

    $user = makeUser();
    $user->assignRole('god');

    expect($user->hasPermissionTo('anything.at.all'))->toBeTrue()
        ->and($user->isSuperAdmin())->toBeTrue();
});

it('does not bypass when no super admin role is configured', function () {
    config()->set('route-permissions.super_admin_role', null);
    makeRole('god');

    $user = makeUser();
    $user->assignRole('god');

    expect($user->hasPermissionTo('anything.at.all'))->toBeFalse()
        ->and($user->isSuperAdmin())->toBeFalse();
});

it('resolves the user relationship on permissions and roles', function () {
    // Both of these relationships referenced an unresolvable User::class in the
    // previous version and threw the moment anyone touched them.
    makePermissions('a');
    makeRole('editor', ['a']);

    $user = makeUser();
    $user->assignRole('editor')->givePermissionTo('a');

    expect(Permission::first()->users)->toHaveCount(1)
        ->and(Role::first()->users)->toHaveCount(1);
});

it('cascades pivot rows when a permission is deleted', function () {
    makePermissions('a');
    $user = makeUser();
    $user->givePermissionTo('a');

    expect(DB::table('permission_user')->count())->toBe(1);

    Permission::where('name', 'a')->delete();

    expect(DB::table('permission_user')->count())->toBe(0);
});
