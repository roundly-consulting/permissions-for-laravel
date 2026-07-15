<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the shipped migrations emit a real Postgres `jsonb` column for `description`
 * on both the roles and permissions tables. SQLite maps jsonb -> text, so the type is
 * invisible there (pinned in SchemaConventionsTest); this lane is the only place the
 * jsonb decision is observable, so it runs against a real server when one is available.
 */
pest()->group('pgsql');

beforeEach(function (): void {
    if (! pgsqlConfigured()) {
        $this->markTestSkipped('Set PERMISSIONS_PGSQL_* to run the PostgreSQL description-column lane.');
    }

    config()->set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => env('PERMISSIONS_PGSQL_HOST', '127.0.0.1'),
        'port' => env('PERMISSIONS_PGSQL_PORT', '5432'),
        'database' => env('PERMISSIONS_PGSQL_DATABASE'),
        'username' => env('PERMISSIONS_PGSQL_USERNAME', 'postgres'),
        'password' => env('PERMISSIONS_PGSQL_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);
    config()->set('database.default', 'pgsql');
    DB::purge('pgsql');

    foreach (['permissions', 'roles'] as $table) {
        Schema::connection('pgsql')->dropIfExists($table);
    }

    foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migration) {
        (require $migration)->up();
    }
});

afterEach(function (): void {
    if (! pgsqlConfigured()) {
        return;
    }

    foreach (['model_permissions', 'model_roles', 'permission_role', 'roles', 'permissions'] as $table) {
        Schema::connection('pgsql')->dropIfExists($table);
    }

    config()->set('database.default', 'testing');
    DB::purge('pgsql');
});

it('emits a jsonb, nullable description on both tables', function (string $table): void {
    /** @var object{data_type: string, is_nullable: string}|null $column */
    $column = DB::connection('pgsql')->selectOne(
        'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, 'description'],
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('jsonb')
        ->and($column->is_nullable)->toBe('YES');
})->with(['roles', 'permissions']);
