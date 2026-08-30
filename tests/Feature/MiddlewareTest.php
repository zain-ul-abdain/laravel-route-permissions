<?php

use Illuminate\Support\Facades\Route;
use Zain\RoutePermissions\Exceptions\UnnamedRouteException;

/**
 * Every test here corresponds to a defect in the previous implementation.
 * Status codes are asserted explicitly because the old API middleware returned
 * a JSON body with no status argument — so an authorization *failure* came back
 * as HTTP 200 and any client checking response.ok treated it as success.
 */
beforeEach(function () {
    Route::middleware(['web', 'route.permission'])->get('/posts/edit', fn () => 'ok')->name('posts.edit');
    Route::middleware(['web', 'permission:posts.publish'])->get('/posts/publish', fn () => 'ok');
    Route::middleware(['web', 'role:admin'])->get('/admin', fn () => 'ok');
    Route::middleware(['web', 'route.permission'])->get('/unnamed', fn () => 'ok');
});

it('returns 401 for a guest rather than throwing', function () {
    // The old middleware called $request->user()->hasPermissionTo() with no
    // null check, so a guest produced a 500 instead of a 401.
    $this->get('/posts/edit')->assertStatus(401);
});

it('returns 403 — not 200 — when the user lacks the permission', function () {
    $user = makeUser();

    $this->actingAs($user)->get('/posts/edit')->assertStatus(403);
});

it('allows the request when the route-name permission is held', function () {
    makePermissions('posts.edit');
    $user = makeUser();
    $user->givePermissionTo('posts.edit');

    $this->actingAs($user)->get('/posts/edit')->assertOk()->assertSee('ok');
});

it('enforces an explicit permission argument', function () {
    makePermissions('posts.publish');
    $user = makeUser();

    $this->actingAs($user)->get('/posts/publish')->assertStatus(403);

    $user->givePermissionTo('posts.publish');
    $user->forgetCachedPermissions();

    $this->actingAs($user)->get('/posts/publish')->assertOk();
});

it('enforces roles', function () {
    makeRole('admin');
    $user = makeUser();

    $this->actingAs($user)->get('/admin')->assertStatus(403);

    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')->assertOk();
});

it('fails loudly on an unnamed route instead of silently allowing or denying', function () {
    $user = makeUser();

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->get('/unnamed'))
        ->toThrow(UnnamedRouteException::class);
});

it('lets a super admin through the middleware', function () {
    config()->set('route-permissions.super_admin_role', 'god');
    makeRole('god');

    $user = makeUser();
    $user->assignRole('god');

    $this->actingAs($user)->get('/posts/edit')->assertOk();
});
