<?php

use Illuminate\Support\Facades\Route;
use Zain\RoutePermissions\Models\Permission;

beforeEach(function () {
    Route::get('/posts', fn () => 'ok')->name('posts.index');
    Route::get('/posts/{post}', fn () => 'ok')->name('posts.show');
    Route::get('/login', fn () => 'ok')->name('login');          // excluded by default
    Route::get('/anonymous', fn () => 'ok');                      // unnamed, skipped
});

it('creates one permission per named route', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $names = Permission::pluck('name')->all();

    expect($names)->toContain('posts.index', 'posts.show')
        ->and(Permission::where('from_route', true)->count())->toBe(count($names));
});

it('skips routes excluded by config', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::where('name', 'login')->exists())->toBeFalse();
});

it('is idempotent', function () {
    $this->artisan('permissions:sync')->assertSuccessful();
    $before = Permission::count();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::count())->toBe($before);
});

it('writes nothing on a dry run', function () {
    $this->artisan('permissions:sync', ['--dry-run' => true])->assertSuccessful();

    expect(Permission::count())->toBe(0);
});

it('reports an orphaned generated permission without deleting it by default', function () {
    Permission::create(['name' => 'gone.away', 'from_route' => true]);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::where('name', 'gone.away')->exists())->toBeTrue();
});

it('prunes orphaned generated permissions when asked', function () {
    Permission::create(['name' => 'gone.away', 'from_route' => true]);

    $this->artisan('permissions:sync', ['--prune' => true, '--force' => true])->assertSuccessful();

    expect(Permission::where('name', 'gone.away')->exists())->toBeFalse();
});

it('never prunes a hand-created permission', function () {
    // from_route defaults to false, marking it as human-authored. Pruning a
    // permission someone added deliberately would be the command silently
    // deleting authorization rules it did not create.
    Permission::create(['name' => 'billing.refund']);

    $this->artisan('permissions:sync', ['--prune' => true, '--force' => true])->assertSuccessful();

    expect(Permission::where('name', 'billing.refund')->exists())->toBeTrue();
});

it('respects an include pattern', function () {
    config()->set('route-permissions.scan.include', ['posts.*']);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::pluck('name')->all())->each->toStartWith('posts.');
});
