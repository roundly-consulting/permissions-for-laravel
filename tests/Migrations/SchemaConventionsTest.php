<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Pins the schema the package emits for **every** `permissions.key_type` value.
 *
 * The junction tables' `model_id` column is the one thing this package's schema
 * derives from config, so it is pinned per key type. The `bigint` default is the
 * shipped schema and must never drift.
 */
function migrateForKeyType(string $keyType): string
{
    $file = tempnam(sys_get_temp_dir(), 'permschema').'.sqlite';
    touch($file);

    config()->set('database.connections.schema_pin', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    config()->set('database.default', 'schema_pin');
    config()->set('permissions.key_type', $keyType);
    DB::purge('schema_pin');

    foreach (migrationSources() as $migration) {
        (require $migration)->up();
    }

    return $file;
}

/** @return list<string> */
function migrationSources(): array
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    return array_values($files);
}

function createStatement(string $table): string
{
    /** @var object{sql: string}|null $row */
    $row = DB::connection('schema_pin')
        ->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

    return preg_replace('/\s+/', ' ', (string) ($row->sql ?? '')) ?? '';
}

afterEach(function (): void {
    // Hand the suite's own connection back — Testbench rolls its migrations back
    // against `database.default` when the application is destroyed.
    config()->set('database.default', 'testing');
    DB::purge('schema_pin');
});

it('emits an unsigned bigint holder key on the shipped default', function (): void {
    migrateForKeyType('bigint');

    expect(createStatement('model_roles'))->toBe(
        'CREATE TABLE "model_roles" ("role_id" integer not null, "model_type" varchar not null, '
        .'"model_id" integer not null, foreign key("role_id") references "roles"("id") on delete cascade, '
        .'primary key ("role_id", "model_id", "model_type"))'
    );

    expect(createStatement('model_permissions'))->toBe(
        'CREATE TABLE "model_permissions" ("permission_id" integer not null, "model_type" varchar not null, '
        .'"model_id" integer not null, foreign key("permission_id") references "permissions"("id") on delete cascade, '
        .'primary key ("permission_id", "model_id", "model_type"))'
    );
});

it('emits a string holder key for uuid and ulid holders', function (string $keyType): void {
    migrateForKeyType($keyType);

    expect(createStatement('model_roles'))->toContain('"model_id" varchar not null')
        ->and(createStatement('model_permissions'))->toContain('"model_id" varchar not null');
})->with(['uuid', 'ulid']);

it('refuses to migrate on an unrecognized key type instead of falling back to bigint', function (): void {
    // A typo must stop the migration, never silently build bigint holder keys for a
    // uuid/ulid-keyed host.
    expect(fn (): string => migrateForKeyType('bigInteger-ish nonsense'))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [permissions.key_type] must be one of [bigint, uuid, ulid] (case-insensitive), [bigInteger-ish nonsense] given.',
    );
});

it('indexes the holder key by [model_id, model_type] and nothing else', function (string $keyType): void {
    migrateForKeyType($keyType);

    foreach (['model_roles', 'model_permissions'] as $table) {
        /** @var list<object{name: string, sql: string|null}> $indexes */
        $indexes = DB::connection('schema_pin')->select(
            'select name, sql from sqlite_master where type = ? and tbl_name = ? and sql is not null',
            ['index', $table],
        );

        expect($indexes)->toHaveCount(1);
        expect(preg_replace('/\s+/', ' ', (string) $indexes[0]->sql))
            ->toBe(sprintf(
                'CREATE INDEX "%s_model_id_model_type_index" on "%s" ("model_id", "model_type")',
                $table,
                $table,
            ));
    }
})->with(['bigint', 'uuid', 'ulid']);

it('keeps the roles and permissions tables independent of the key type', function (string $keyType): void {
    migrateForKeyType($keyType);

    // Roles and permissions always own an auto-incrementing key — `key_type`
    // describes the *holder*, never this package's own tables.
    expect(createStatement('roles'))->toContain('"id" integer primary key autoincrement not null')
        ->and(createStatement('permissions'))->toContain('"id" integer primary key autoincrement not null');
})->with(['bigint', 'uuid', 'ulid']);

it('carries a nullable jsonb description on roles and permissions', function (): void {
    migrateForKeyType('bigint');

    // `description` is a per-locale map stored as jsonb (translatable's convention);
    // Laravel maps jsonb to `text` on SQLite. The column is always nullable.
    expect(createStatement('roles'))->toContain('"description" text')
        ->and(createStatement('roles'))->not->toContain('"description" text not null')
        ->and(createStatement('permissions'))->toContain('"description" text')
        ->and(createStatement('permissions'))->not->toContain('"description" text not null');
});
