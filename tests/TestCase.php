<?php

namespace Zain\RoutePermissions\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Zain\RoutePermissions\Concerns\HasPermissions;
use Zain\RoutePermissions\RoutePermissionsServiceProvider;

class User extends Authenticatable
{
    use HasPermissions;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RoutePermissionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The `web` middleware group starts a session, which needs an
        // encryption key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig());

        $app['config']->set('route-permissions.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);

        // Caching is exercised by its own test file; elsewhere it would only
        // mask stale reads behind an array store.
        $app['config']->set('route-permissions.cache.enabled', false);
    }

    protected function connectionConfig(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', 'postgres'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'testing'),
                'password' => env('DB_PASSWORD', 'testing'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', 'mysql'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'testing'),
                'password' => env('DB_PASSWORD', 'testing'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // Off by default on a hand-built connection array, and without
                // it SQLite silently ignores every ON DELETE CASCADE — so the
                // cascade tests would pass against MySQL and Postgres while
                // quietly asserting nothing here.
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        // A fresh in-memory SQLite database per test makes this unnecessary,
        // which is precisely why it has to be explicit: against PostgreSQL or
        // MySQL the schema persists between tests, and the raw create below is
        // not a migration so nothing rolls it back. Without the drops, every
        // test after the first fails on "table already exists".
        $this->dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function dropAllTables(): void
    {
        $tables = config('route-permissions.tables');

        // Children before parents, so foreign keys never block a drop.
        foreach ([
            $tables['permission_user'] ?? 'permission_user',
            $tables['permission_role'] ?? 'permission_role',
            $tables['role_user'] ?? 'role_user',
            $tables['permissions'] ?? 'permissions',
            $tables['roles'] ?? 'roles',
            'users',
            'migrations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
