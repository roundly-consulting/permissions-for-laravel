<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The catalog cache is shared between processes (web workers, queue workers, a deploy
 * migration), but an uncommitted write is visible only on its own connection. Two
 * connections to one database stand in for two processes: `writer` is this process,
 * `reader` is a concurrent request with its own registrar over the same cache store.
 *
 * On the sqlite legs the two connections share a file; on the postgres leg they are two
 * sessions on the suite's own (already migrated) database — real MVCC.
 */
beforeEach(function (): void {
    $this->previousDefault = config('database.default');
    $this->catalogFile = null;

    if (DriverMatrix::driver() === 'pgsql') {
        $connection = config("database.connections.{$this->previousDefault}");
    } else {
        $this->catalogFile = tempnam(sys_get_temp_dir(), 'permissions-catalog-');
        $connection = [
            'driver' => 'sqlite',
            'database' => $this->catalogFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }

    config()->set('database.connections.writer', $connection);
    config()->set('database.connections.reader', $connection);
    config()->set('database.default', 'writer');

    if ($this->catalogFile !== null) {
        $migrations = [
            __DIR__.'/../database/migrations/0001_01_01_000000_create_users_table.php',
            ...glob(__DIR__.'/../../database/migrations/*.php') ?: [],
        ];

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }
    }

    Permissions::permission('existing');

    // Another process: its own registrar (memo, pending state), the same shared store.
    $this->otherProcess = new PermissionRegistrar(app('cache'));

    // What that process sees, read on its own connection with a fresh request memo.
    $this->otherSees = function (string $name): bool {
        config()->set('database.default', 'reader');

        try {
            $this->otherProcess->flushMemo();

            return $this->otherProcess->exists($name);
        } finally {
            config()->set('database.default', 'writer');
        }
    };
});

afterEach(function (): void {
    DB::purge('writer');
    DB::purge('reader');
    config()->set('database.default', $this->previousDefault);

    if ($this->catalogFile !== null) {
        @unlink($this->catalogFile);
    }
});

it('serves a permission created in a transaction once it commits, even if another process cached mid-transaction', function (): void {
    DB::connection('writer')->transaction(function (): void {
        Permissions::permission('new.perm');

        // A concurrent request loads (and caches) the catalog before the commit.
        expect(($this->otherSees)('new.perm'))->toBeFalse();
    });

    expect(($this->otherSees)('new.perm'))->toBeTrue()
        ->and(Permissions::exists('new.perm'))->toBeTrue();
});

it('lets the gate grant a permission created and granted in a transaction once it commits', function (): void {
    $user = User::query()->create(['name' => 'ada']);

    DB::connection('writer')->transaction(function () use ($user): void {
        Permissions::permission('new.perm');
        $user->givePermissionTo('new.perm');

        ($this->otherSees)('new.perm'); // a concurrent request caches the pre-commit catalog
    });

    expect(Gate::forUser($user->refresh())->allows('new.perm'))->toBeTrue();
});

it('drops a permission deleted in a transaction once it commits, even if another process cached mid-transaction', function (): void {
    DB::connection('writer')->transaction(function (): void {
        Permission::query()->where('name', 'existing')->firstOrFail()->delete();

        expect(($this->otherSees)('existing'))->toBeTrue();
    });

    expect(($this->otherSees)('existing'))->toBeFalse()
        ->and(Permissions::exists('existing'))->toBeFalse();
});

it('lets the writing connection see its own uncommitted catalog write', function (): void {
    DB::connection('writer')->transaction(function (): void {
        Permissions::permission('new.perm');

        expect(Permissions::exists('new.perm'))->toBeTrue()
            ->and(Permissions::permissions()->pluck('name')->sort()->values()->all())
            ->toBe(['existing', 'new.perm']);
    });
});

it('never puts an uncommitted catalog in the shared store, so a rollback leaves no phantom', function (): void {
    Permissions::permissions(); // the committed catalog is cached

    DB::connection('writer')->beginTransaction();
    Permissions::permission('ghost');
    expect(Permissions::exists('ghost'))->toBeTrue(); // read inside the transaction
    DB::connection('writer')->rollBack();

    expect(($this->otherSees)('ghost'))->toBeFalse()
        ->and(Permissions::exists('ghost'))->toBeFalse()
        ->and(array_column(Cache::get(config('permissions.cache.key')), 'name'))->toBe(['existing']);
});

it('keeps a permission whose deletion rolled back', function (): void {
    DB::connection('writer')->beginTransaction();
    Permission::query()->where('name', 'existing')->firstOrFail()->delete();
    expect(Permissions::exists('existing'))->toBeFalse();
    DB::connection('writer')->rollBack();

    expect(($this->otherSees)('existing'))->toBeTrue()
        ->and(Permissions::exists('existing'))->toBeTrue();
});

it('waits for the outermost commit, not a nested savepoint', function (): void {
    DB::connection('writer')->transaction(function (): void {
        DB::connection('writer')->transaction(function (): void {
            Permissions::permission('new.perm');
        });

        // The savepoint released, the transaction did not commit: still invisible elsewhere.
        expect(($this->otherSees)('new.perm'))->toBeFalse();

        try {
            DB::connection('writer')->transaction(function (): void {
                throw new RuntimeException('an inner savepoint rolls back');
            });
        } catch (RuntimeException) {
        }

        expect(Permissions::exists('new.perm'))->toBeTrue();
    });

    expect(($this->otherSees)('new.perm'))->toBeTrue();
});

it('defers an explicit forget inside a transaction to its commit too', function (): void {
    DB::connection('writer')->transaction(function (): void {
        // A bulk write bypasses the model events; the host invalidates by hand.
        DB::connection('writer')->table('permissions')->insert(['name' => 'bulk.perm']);
        Permissions::cache()->forget();

        expect(($this->otherSees)('bulk.perm'))->toBeFalse();
    });

    expect(($this->otherSees)('bulk.perm'))->toBeTrue();
});

it('defers the grant invalidation of an outer transaction to its commit', function (): void {
    $role = Permissions::role('editor');
    Permissions::permissions(); // cached

    DB::connection('writer')->transaction(function () use ($role): void {
        Permissions::for($role)->givePermissionTo('existing');

        expect(Cache::has(config('permissions.cache.key')))->toBeTrue();
    });

    expect(Cache::has(config('permissions.cache.key')))->toBeFalse();
});

it('recovers when a transaction ends without its connection event', function (): void {
    $writer = DB::connection('writer');
    $writer->beginTransaction();
    Permissions::permission('new.perm');

    // The commit event never reaches the listener (no dispatcher, a faked one, a lost
    // connection): the next read notices the transaction is over and drops the store.
    ($this->otherSees)('new.perm');
    $writer->unsetEventDispatcher();
    $writer->commit();

    expect(Permissions::exists('new.perm'))->toBeTrue()
        ->and(($this->otherSees)('new.perm'))->toBeTrue()
        ->and(Cache::has(config('permissions.cache.key')))->toBeTrue();
});

it('forgets straight away when no transaction is open', function (): void {
    Permissions::permissions();
    expect(Cache::has(config('permissions.cache.key')))->toBeTrue();

    Role::query()->create(['name' => 'viewer']);

    expect(Cache::has(config('permissions.cache.key')))->toBeFalse();
});
