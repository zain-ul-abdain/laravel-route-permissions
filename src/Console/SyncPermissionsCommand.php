<?php

namespace Zain\RoutePermissions\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Zain\RoutePermissions\Models\Permission;
use Zain\RoutePermissions\PermissionRegistry;

/**
 * Scaffolds one permission per named route.
 *
 * This is the piece worth having: you define routes anyway, so the permission
 * catalogue can be derived from them rather than hand-maintained and quietly
 * drifting out of sync.
 *
 * The important design constraint is that permissions are *written to the
 * database once* and then referenced by name. The previous implementation
 * re-derived the permission string from the controller class name on every
 * request, which meant a rename silently changed the required permission and
 * orphaned every existing grant, with no diff and no warning.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync
        {--dry-run : Show what would change without writing anything}
        {--prune : Delete generated permissions whose route no longer exists}
        {--force : Skip the confirmation prompt when pruning}';

    protected $description = 'Create a permission for every named route, and report drift.';

    public function handle(PermissionRegistry $registry): int
    {
        $routeNames = $this->scannableRouteNames();

        if ($routeNames === []) {
            $this->components->warn('No routes matched the scan configuration. Check route-permissions.scan.');

            return self::SUCCESS;
        }

        $existing = Permission::query()->pluck('from_route', 'name');

        $new = array_values(array_diff($routeNames, $existing->keys()->all()));

        // Only generated permissions can be orphaned. One a human created by
        // hand is theirs to keep, even if it matches no route.
        $orphaned = $existing
            ->filter(fn ($fromRoute) => (bool) $fromRoute)
            ->keys()
            ->reject(fn ($name) => in_array($name, $routeNames, true))
            ->values()
            ->all();

        $this->report($routeNames, $new, $orphaned);

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if ($new !== []) {
            $now = now();

            Permission::query()->insert(array_map(fn (string $name) => [
                'name' => $name,
                'label' => $this->labelFor($name),
                'from_route' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $new));

            $this->components->info(count($new).' permission(s) created.');
        }

        if ($orphaned !== [] && $this->option('prune')) {
            if ($this->option('force') || $this->confirm('Delete '.count($orphaned).' orphaned permission(s)? Grants referencing them will be removed too.')) {
                Permission::query()->whereIn('name', $orphaned)->where('from_route', true)->delete();
                $this->components->info(count($orphaned).' permission(s) pruned.');
            }
        }

        $registry->flushCatalogue();

        if ($new === [] && ($orphaned === [] || ! $this->option('prune'))) {
            $this->components->info('Permission catalogue already in sync.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $all
     * @param  array<int, string>  $new
     * @param  array<int, string>  $orphaned
     */
    protected function report(array $all, array $new, array $orphaned): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Routes scanned</>', (string) count($all));
        $this->components->twoColumnDetail('<fg=green>New permissions</>', (string) count($new));
        $this->components->twoColumnDetail('<fg=yellow>Orphaned</>', (string) count($orphaned));
        $this->newLine();

        foreach ($new as $name) {
            $this->components->twoColumnDetail("  <fg=green>+</> {$name}", '<fg=gray>new</>');
        }

        foreach ($orphaned as $name) {
            $this->components->twoColumnDetail(
                "  <fg=yellow>~</> {$name}",
                $this->option('prune') ? '<fg=red>will be pruned</>' : '<fg=gray>no matching route</>'
            );
        }

        if ($orphaned !== [] && ! $this->option('prune')) {
            $this->newLine();
            $this->components->warn('Orphaned permissions left in place. Re-run with --prune to remove them.');
        }
    }

    /**
     * @return array<int, string>
     */
    protected function scannableRouteNames(): array
    {
        $include = (array) config('route-permissions.scan.include', ['*']);
        $exclude = (array) config('route-permissions.scan.exclude', []);
        $middleware = (array) config('route-permissions.scan.middleware', []);

        $names = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            if (! Str::is($include, $name) || Str::is($exclude, $name)) {
                continue;
            }

            if ($middleware !== [] && array_intersect($middleware, $route->gatherMiddleware()) === []) {
                continue;
            }

            $names[$name] = true;
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    protected function labelFor(string $name): string
    {
        return Str::of($name)->replace(['.', '_', '-'], ' ')->title()->toString();
    }
}
