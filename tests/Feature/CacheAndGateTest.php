<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

it('resolves permissions without an N+1 across roles', function () {
    // The previous implementation looped roles and lazy-loaded each role's
    // permissions, so the query count grew with the number of roles a user
    // held — on every request that carried the middleware.
    makePermissions(['a', 'b', 'c', 'd']);
    makeRole('one', ['a']);
    makeRole('two', ['b']);
    makeRole('three', ['c']);

    $user = makeUser();
    $user->assignRole(['one', 'two', 'three']);
    $user->forgetCachedPermissions();

    DB::enableQueryLog();
    $user->getPermissionNames();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Two: direct grants, and grants inherited through roles.
    expect($queries)->toBeLessThanOrEqual(2);
});

it('caches resolved permissions and invalidates on change', function () {
    config()->set('route-permissions.cache.enabled', true);
    config()->set('cache.default', 'array');

    makePermissions(['a', 'b']);
    $user = makeUser();
    $user->givePermissionTo('a');

    expect($user->hasPermissionTo('a'))->toBeTrue()
        ->and($user->hasPermissionTo('b'))->toBeFalse();

    // givePermissionTo() forgets the cached entry, so this must be visible
    // immediately rather than after the TTL expires.
    $user->givePermissionTo('b');

    expect($user->hasPermissionTo('b'))->toBeTrue();
});

it('serves a cached result without hitting the database again', function () {
    config()->set('route-permissions.cache.enabled', true);
    config()->set('cache.default', 'array');

    makePermissions('a');
    $user = makeUser();
    $user->givePermissionTo('a');

    $user->hasPermissionTo('a');   // warms

    DB::enableQueryLog();
    $user->hasPermissionTo('a');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('resolves through the Gate so $user->can() works', function () {
    makePermissions('posts.edit');
    $user = makeUser();
    $user->givePermissionTo('posts.edit');

    expect($user->can('posts.edit'))->toBeTrue()
        ->and($user->can('posts.delete'))->toBeFalse();
});

it('does not hard-deny abilities it knows nothing about', function () {
    // Gate::before returning false would override every other policy in the
    // application. It must return null on a miss so resolution falls through.
    Gate::define('unrelated-ability', fn () => true);

    $user = makeUser();

    expect($user->can('unrelated-ability'))->toBeTrue();
});
