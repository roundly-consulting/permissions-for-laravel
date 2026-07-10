<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

function memoValue(PermissionRegistrar $registrar): mixed
{
    return (new ReflectionProperty($registrar, 'permissions'))->getValue($registrar);
}

it('flushes only the in-process memo, leaving the shared store intact', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();

    expect(memoValue($registrar))->not->toBeNull();

    $registrar->flushMemo();

    expect(memoValue($registrar))->toBeNull();

    // The next read is served from the shared store — no database round-trip.
    DB::enableQueryLog();
    $permissions = $registrar->getPermissions();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($permissions->pluck('name')->all())->toBe(['auth.users.view']);
});

it('flushes the memo at an octane request boundary', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();

    expect(memoValue($registrar))->not->toBeNull();

    // Dispatched by string name so the package never depends on laravel/octane.
    event('Laravel\Octane\Events\RequestReceived');

    expect(memoValue($registrar))->toBeNull();
});

it('lets a fresh registrar re-read the store after invalidation', function (): void {
    Permission::findOrCreate('auth.users.view');
    app(PermissionRegistrar::class)->getPermissions();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // A bulk insert bypasses model events; the store was already cleared above.
    Permission::query()->getConnection()->table('permissions')->insert(['name' => 'auth.users.edit']);

    $fresh = new PermissionRegistrar(app('cache'));

    expect($fresh->getPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['auth.users.edit', 'auth.users.view']);
});
