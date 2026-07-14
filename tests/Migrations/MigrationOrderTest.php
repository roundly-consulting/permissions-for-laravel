<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Support\MigrationPublisher;

/**
 * Migrations are published in directory order, so directory order MUST be
 * dependency order. A sqlite-only suite cannot prove that on its own — SQLite
 * silently accepts a CREATE TABLE that references a missing parent — so the
 * load-bearing assertion here is the **structural** one: parse every foreign key
 * out of the sources and assert the parent's CREATE sorts first.
 */

/** @return list<string> */
function orderedSources(): array
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    return array_values($files);
}

/**
 * The table each migration CREATEs, keyed by its position in directory order.
 *
 * The package's `Schema::create()` calls take a *variable* (the table name is
 * config-driven), so the table is derived from the filename rather than a literal.
 *
 * @return array<string, int>
 */
function createdTablePositions(): array
{
    $positions = [];

    foreach (orderedSources() as $index => $file) {
        $name = MigrationPublisher::nameFor($file);

        if (preg_match('/^create_(.+)_table$/', $name, $matches) === 1) {
            $positions[$matches[1]] = $index;
        }
    }

    return $positions;
}

it('creates every foreign key target before the table that references it', function (): void {
    $positions = createdTablePositions();
    $edges = 0;

    foreach (orderedSources() as $index => $file) {
        $source = (string) file_get_contents($file);
        $child = MigrationPublisher::nameFor($file);

        // Both of Laravel's FK forms, and this package's config-driven variants:
        //   ->constrained('parent') / ->constrained(Registrar::parentTable())
        //   ->references('id')->on('parent')
        // The argument may itself be a call, so one level of nesting is allowed.
        $argument = '((?:[^()]|\([^()]*\))*)';
        preg_match_all('/->constrained\(\s*'.$argument.'\s*\)/', $source, $constrained);
        preg_match_all('/->on\(\s*'.$argument.'\s*\)/', $source, $referenced);

        foreach ([...$constrained[1], ...$referenced[1]] as $target) {
            $parent = parentTableFrom($target);

            $this->assertNotNull(
                $parent,
                "Could not resolve the FK target `{$target}` in {$child} — teach this pin the new form.",
            );

            $edges++;

            $this->assertArrayHasKey(
                $parent,
                $positions,
                "{$child} constrains onto `{$parent}`, which no migration creates.",
            );

            $this->assertLessThan(
                $index,
                $positions[$parent],
                "{$child} (position {$index}) constrains onto `{$parent}`, created at position {$positions[$parent]}.",
            );
        }
    }

    // Guard the guard: the package really does emit four foreign keys, so a pin
    // that found none would be passing vacuously.
    expect($edges)->toBe(4);
});

/**
 * Resolve an FK target expression to a table name. The package names its parents
 * through the registrar (config-driven), so both the literal and the accessor
 * form must be understood.
 */
function parentTableFrom(string $expression): ?string
{
    $expression = trim($expression);

    if (preg_match('/^[\'"](.+)[\'"]$/', $expression, $literal) === 1) {
        return $literal[1];
    }

    return match ($expression) {
        'PermissionRegistrar::rolesTable()' => 'roles',
        'PermissionRegistrar::permissionsTable()' => 'permissions',
        'PermissionRegistrar::permissionRoleTable()' => 'permission_role',
        default => null,
    };
}

it('migrates the published filenames into a fresh empty database', function (): void {
    $directory = sys_get_temp_dir().'/perm_published_'.uniqid();
    File::makeDirectory($directory, recursive: true);

    $timestamp = now();

    foreach (orderedSources() as $offset => $file) {
        $destination = MigrationPublisher::destination(
            MigrationPublisher::nameFor($file),
            $directory,
            $timestamp->copy()->addSeconds($offset),
        );

        File::copy($file, $destination);
    }

    $database = tempnam(sys_get_temp_dir(), 'permorder').'.sqlite';
    touch($database);

    config()->set('database.connections.order_pin', ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
    config()->set('database.default', 'order_pin');
    DB::purge('order_pin');

    expect(Schema::hasTable('roles'))->toBeFalse();

    $this->artisan('migrate', ['--path' => $directory, '--realpath' => true])->assertSuccessful();

    foreach (['permissions', 'roles', 'permission_role', 'model_roles', 'model_permissions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    // The FK targets are load-bearing, not incidental — if these constraints
    // vanished, the order test above would be pinning nothing.
    expect(collect(Schema::getForeignKeys('permission_role'))->pluck('foreign_table')->sort()->values()->all())
        ->toBe(['permissions', 'roles']);

    DB::purge('order_pin');
    File::deleteDirectory($directory);
});

it('publishes the migrations in dependency order', function (): void {
    $timestamp = now();
    $destinations = [];

    foreach (orderedSources() as $offset => $file) {
        $destinations[] = basename(MigrationPublisher::destination(
            MigrationPublisher::nameFor($file),
            '/database/migrations',
            $timestamp->copy()->addSeconds($offset),
        ));
    }

    $sorted = $destinations;
    sort($sorted);

    // The host's migrator runs published files in filename order — which must be
    // the order they were published in.
    expect($destinations)->toBe($sorted)
        ->and($destinations)->toHaveCount(5)
        ->and($destinations[0])->toContain('create_permissions_table')
        ->and($destinations[1])->toContain('create_roles_table')
        ->and($destinations[2])->toContain('create_permission_role_table');
});
