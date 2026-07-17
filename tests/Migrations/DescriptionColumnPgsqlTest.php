<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Proves the shipped migrations emit a real Postgres `jsonb` column for `description` on
 * both the roles and permissions tables. SQLite maps jsonb -> text, so the type is
 * invisible there (pinned structurally in SchemaConventionsTest); a real server is the only
 * place the jsonb decision is observable.
 *
 * ## Migrated off this package's bespoke `PERMISSIONS_PGSQL_*` lane
 *
 * This used to run under `pest()->group('pgsql')` against a hand-built `pgsql` connection
 * from `PERMISSIONS_PGSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD`, gated by a
 * `pgsqlConfigured()` helper that asked whether **an env var was set** — never whether an
 * engine was **reachable**. That is a fourth bespoke convention in a fleet that already had
 * three, and it had the same hole as the others: the lane reported green by skipping, and
 * nothing failed when the server was absent.
 *
 * It now runs on the fleet's standard whole-suite `TESTING_DB_*` leg: the suite itself is
 * on Postgres, so this reads the type off the table the suite actually migrated rather than
 * dropping and re-migrating tables on a side connection — which is precisely the
 * two-sessions-one-database shape that corrupted other rows' runs.
 */
$onPostgres = fn (): bool => DriverMatrix::driver() !== 'pgsql';

it('emits a jsonb, nullable description on both tables', function (string $table): void {
    /** @var object{data_type: string, is_nullable: string}|null $column */
    $column = DB::selectOne(
        'select data_type, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, 'description'],
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('jsonb')
        ->and($column->is_nullable)->toBe('YES');
})->with(['roles', 'permissions'])->skip($onPostgres, 'the column type is only observable on a real engine');

/**
 * The type is worth nothing if the attribute cannot round-trip through it. `jsonb`
 * **sorts object keys** (by length, then bytes) and canonicalises whitespace, so a
 * translation map does not come back in insertion order — asserted key-by-key rather than
 * against a whole literal array, because Pest's `toBe` is `===` and IS order-sensitive on
 * PHP assoc arrays.
 *
 * This is the half the old lane never had: it read `information_schema` and stopped, so it
 * proved the column was declared jsonb without ever proving a description could be written
 * to and read from it.
 */
it('round-trips a translatable description through jsonb', function (): void {
    $role = Role::query()->create([
        'name' => 'editor',
        'description' => ['en' => 'Editor', 'de' => 'Redakteur', 'cs' => 'Editor CS'],
    ]);

    $fresh = $role->fresh();

    // The stored map, key by key: the contract here is the VALUE per locale, and jsonb
    // gives no promise about key order.
    $stored = $fresh->getTranslations('description');

    expect($stored['en'] ?? null)->toBe('Editor')
        ->and($stored['de'] ?? null)->toBe('Redakteur')
        ->and($stored['cs'] ?? null)->toBe('Editor CS');

    // And the resolved read for the current locale still lands on a plain string.
    app()->setLocale('de');
    expect($role->fresh()->description)->toBe('Redakteur');

    app()->setLocale('en');
})->skip($onPostgres, 'jsonb key-order canonicalisation is only observable on a real engine');

/**
 * `jsonb` is GIN-indexable and has real equality, which is the whole reason it was chosen
 * over `json` (Postgres `json` has NO equality operator, so a `where` on one is
 * structurally impossible). Pinning a containment query proves the choice actually bought
 * what it was meant to.
 */
it('queries into the jsonb description', function (): void {
    Permission::query()->create(['name' => 'posts.edit', 'description' => ['en' => 'Edit posts']]);
    Permission::query()->create(['name' => 'posts.delete', 'description' => ['en' => 'Delete posts']]);

    $found = Permission::query()->where('description->en', 'Edit posts')->pluck('name')->all();

    expect($found)->toBe(['posts.edit']);
})->skip($onPostgres, 'json path equality is only meaningful on a real engine');
