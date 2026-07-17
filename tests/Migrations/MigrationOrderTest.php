<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Permissions\PermissionsServiceProvider;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * M + P + R for the five permission tables.
 *
 * Every table expression in this package's migrations is the `Class::method()` form —
 * `Schema::create(PermissionRegistrar::rolesTable())`,
 * `->constrained(PermissionRegistrar::permissionsTable())` — because table names are
 * configurable. The resolver **never guesses on a non-literal**: an unmapped expression
 * FAILS the assertion rather than silently dropping the edge, which is what keeps
 * `foreignKeys: 4` honest instead of a number that passes over an empty parse.
 */
$migrations = __DIR__.'/../../database/migrations';

$tableResolvers = [
    'PermissionRegistrar::rolesTable()' => 'roles',
    'PermissionRegistrar::permissionsTable()' => 'permissions',
    'PermissionRegistrar::permissionRoleTable()' => 'permission_role',
    'PermissionRegistrar::modelRolesTable()' => 'model_roles',
    'PermissionRegistrar::modelPermissionsTable()' => 'model_permissions',
];

/**
 * M — the structural, engine-independent order pin.
 *
 * Publish order IS run order (directory sort), so a migration that constrains onto a table
 * an earlier one has not created yet is uninstallable in a host. Five packages shipped
 * exactly that under green SQLite suites, because SQLite happily creates a table whose
 * foreign key names a missing parent and only complains at insert time.
 *
 * `foreignKeys: 4` pins the edge count: permission_role has 2 (permissions + roles),
 * model_roles has 1, model_permissions has 1. The junction tables' `model_id` is
 * deliberately NOT constrained — a holder can live in any table, and its key type is
 * configurable (`permissions.key_type`).
 */
it('has a runnable migration order', function () use ($migrations, $tableResolvers): void {
    expect($migrations)->toHaveRunnableMigrationOrder(
        foreignKeys: 4,
        tableResolvers: $tableResolvers,
    );
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies — a duplicate-table failure (bug #5, on
 * three packages). `count: 5` pins the file count so neither check can pass over an empty
 * or relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(PermissionsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(PermissionsServiceProvider::class)->toPublishMigrationsTimestamped('permissions-migrations', 5);
});

/**
 * R — the real-engine proof, both halves.
 *
 * The structural pin above is engine-independent; this is the definitive one. `migrations: 5`
 * pins the count, and the expectation additionally fails a set that "applies cleanly" while
 * creating no tables — an empty `up()` otherwise passes and proves nothing.
 */
it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 5);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The negative control — and the reason it is adoptable HERE where it was not for
 * refresh-tokens or credits: this package has real FK edges, so a reversed order gives
 * Postgres something to refuse. A green FK test proves nothing until you have watched the
 * engine actually reject the broken order (forms #28). This fails loudly if the engine
 * ACCEPTS the reordered set, which is what makes the positive half above meaningful.
 */
it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(
        fn (array $files): array => array_reverse($files),
        'pgsql',
    );
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The driver-truth pin: compares the env-declared driver against what the connection
 * itself answers, so a leg that exports the location vars but not `TESTING_DB_DRIVER` (or
 * a TestCase that decapitates the base case by overriding `defineEnvironment()` without
 * `parent::`) reds instead of quietly running sqlite and reporting green as a "postgres"
 * job. Strictly stronger than reading a skip count by hand.
 */
it('runs on the driver the leg declared', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});
