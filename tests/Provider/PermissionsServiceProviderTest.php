<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Permissions\Commands\CacheResetCommand;
use RoundlyConsulting\Permissions\Commands\PruneOrphansCommand;
use RoundlyConsulting\Permissions\PermissionsServiceProvider;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;

/** @return array<string, string> */
function publishesFor(string $tag): array
{
    return ServiceProvider::pathsToPublish(PermissionsServiceProvider::class, $tag);
}

it('never auto-loads its migrations', function (): void {
    // The fleet policy: a package publishes its migrations and loads nothing. A bare
    // `php artisan migrate` in a host must not create this package's tables.
    $loaded = app('migrator')->paths();

    expect($loaded)->not->toContain(realpath(__DIR__.'/../../database/migrations'));
});

it('publishes every migration under the unchanged tag, timestamp-injected', function (): void {
    $published = publishesFor('permissions-migrations');

    expect($published)->toHaveCount(5);

    $destinations = array_values(array_map('basename', $published));
    sort($destinations);

    foreach ($destinations as $destination) {
        expect($destination)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_(0001_01_01_000\d{3}_)?create_\w+_table\.php$/');
    }

    // Published order is dependency order.
    expect($destinations[0])->toContain('create_permissions_table')
        ->and($destinations[1])->toContain('create_roles_table')
        ->and($destinations[2])->toContain('create_permission_role_table');
});

it('publishes the config file under the unchanged tag', function (): void {
    $published = publishesFor('permissions-config');

    expect(array_values(array_map('basename', $published)))->toBe(['permissions.php'])
        ->and(array_keys($published)[0])->toEndWith('config/permissions.php');
});

it('registers both console commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('permissions:cache-reset')
        ->and($commands)->toContain('permissions:prune-orphans');

    expect(app(CacheResetCommand::class))->toBeInstanceOf(CacheResetCommand::class)
        ->and(app(PruneOrphansCommand::class))->toBeInstanceOf(PruneOrphansCommand::class);
});

it('registers the registrar as a singleton', function (): void {
    expect(app(PermissionRegistrar::class))->toBe(app(PermissionRegistrar::class));
});

it('registers the toolkit blueprint macros before the migrator runs', function (): void {
    expect(Blueprint::hasMacro('ownerKey'))->toBeTrue();
});

it('reports the package in about', function (): void {
    Artisan::call('about', ['--only' => 'permissions']);
    $output = Artisan::output();

    expect($output)->toContain('Role model')
        ->and($output)->toContain('Role')
        ->and($output)->toContain('Holder key type')
        ->and($output)->toContain('bigint')
        ->and($output)->toContain('Gate check');
});

/**
 * A — the secret-safe `about` capture.
 *
 * Purchases #13 is the bug this expectation exists for: the fleet's most credential-heavy
 * `about` section was guarded by negative assertions against `app(Kernel::class)->output()`,
 * which returns `''` — every "does not leak" check was vacuous. This package's own test was
 * already on the right reader and already guarded the guard, so adopting the expectation is
 * not a bug fix here; it is the same proof with the ordering enforced by the assertion
 * rather than by this file remembering to do it: (1) output non-empty, (2) every
 * `mustRender` string present, (3) only then no secret renders.
 *
 * What this package's config holds is not credentials but HOST TOPOLOGY — the cache store,
 * the cache key, the table names — which must render as presence and counts, never values.
 */
it('never renders host topology or a swapped model namespace in about', function (): void {
    config()->set('permissions.models.role', CustomRole::class);
    config()->set('permissions.cache.store', 'tenant-redis-eu-west');
    config()->set('permissions.cache.key', 'acme.internal.permissions');
    config()->set('permissions.table_names.roles', 'acme_authz_roles');

    expect('permissions')->toLeakNoSecrets(
        secrets: [
            // The host's infrastructure: which store and key back the catalog cache names
            // its deployment, not this package's behaviour.
            'tenant-redis-eu-west',
            'acme.internal.permissions',
            // A renamed table describes the host's schema; the section reports HOW MANY
            // were renamed, never to what.
            'acme_authz_roles',
            // The model renders by base name, never its namespace — a namespace names the
            // host's own application structure.
            'RoundlyConsulting\\Permissions\\Tests\\Fixtures',
        ],
        mustRender: [
            // The positive half: each is the safe report standing in for one of the
            // secrets above, so it also proves the line rendered rather than being
            // silently absent.
            'CustomRole',
            '1 renamed',
        ],
    );
});
