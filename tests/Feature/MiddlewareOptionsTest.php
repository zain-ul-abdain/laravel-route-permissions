<?php

use Illuminate\Support\Facades\Route;

/**
 * Behaviours taken from how role middleware is actually written in production:
 * pipe-delimited lists, a redirect rather than a 403 for browsers, and an
 * explicit guard when admin and customer auth are separate.
 */
beforeEach(function () {
    Route::middleware(['web', 'role:admin|editor'])->get('/piped-role', fn () => 'ok');
    Route::middleware(['web', 'permission:posts.edit|posts.publish'])->get('/piped-permission', fn () => 'ok');
    Route::middleware(['web', 'role:admin'])->get('/needs-admin', fn () => 'ok');
    Route::get('/denied', fn () => 'denied page')->name('access.denied');
});

it('accepts a pipe-delimited role list', function () {
    makeRole('editor');
    $user = makeUser();
    $user->assignRole('editor');

    $this->actingAs($user)->get('/piped-role')->assertOk();
});

it('accepts a pipe-delimited permission list', function () {
    makePermissions(['posts.edit', 'posts.publish']);
    $user = makeUser();
    $user->givePermissionTo('posts.publish');

    $this->actingAs($user)->get('/piped-permission')->assertOk();
});

it('still denies when none of the piped names match', function () {
    makeRole('admin');
    makeRole('editor');

    $this->actingAs(makeUser())->get('/piped-role')->assertStatus(403);
});

it('redirects a browser request when a redirect route is configured', function () {
    config()->set('route-permissions.redirect_to', 'access.denied');
    makeRole('admin');

    $this->actingAs(makeUser())
        ->get('/needs-admin')
        ->assertRedirect(route('access.denied'));
});

it('still returns 403 for a JSON request even when a redirect is configured', function () {
    // An API client cannot follow a redirect meaningfully — it needs the status.
    config()->set('route-permissions.redirect_to', 'access.denied');
    makeRole('admin');

    $this->actingAs(makeUser())
        ->getJson('/needs-admin')
        ->assertStatus(403);
});

it('resolves the user through a configured guard', function () {
    config()->set('route-permissions.guard', 'web');
    makeRole('admin');

    $user = makeUser();
    $user->assignRole('admin');

    $this->actingAs($user, 'web')->get('/needs-admin')->assertOk();
});

it('denies when the configured guard has no authenticated user', function () {
    config()->set('route-permissions.guard', 'web');

    $this->get('/needs-admin')->assertStatus(401);
});
