<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * The catalog cache must hold plain scalars, never Eloquent models.
 *
 * Laravel ships `cache.serializable_classes => false`, which unserializes cached
 * payloads with `allowed_classes: false` — any cached object comes back as
 * __PHP_Incomplete_Class and blew up every roles/permissions read with
 * "Cannot assign __PHP_Incomplete_Class to property ... $permissions".
 */
it('caches only scalars so a no-classes unserialize policy still works', function (): void {
    Permission::findOrCreate('auth.users.view');

    app(PermissionRegistrar::class)->getPermissions();

    $cached = Cache::get(config('permissions.cache.key'));

    expect($cached)->toBeArray();

    foreach ($cached as $row) {
        expect($row)->toBeArray()
            ->and($row['name'])->toBeString();

        foreach ($row as $value) {
            expect(is_scalar($value))->toBeTrue();
        }
    }
});

it('still returns usable models built from the cached scalars', function (): void {
    Permission::findOrCreate('auth.users.view');

    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();
    $registrar->flushMemo(); // force a read back through the cache

    $permissions = $registrar->getPermissions();

    expect($permissions->first())->toBeInstanceOf(Permission::class)
        ->and($permissions->first()->name)->toBe('auth.users.view')
        ->and($permissions->first()->getKey())->not->toBeNull()
        ->and($permissions->first()->exists)->toBeTrue()
        ->and($registrar->permissionExists('auth.users.view'))->toBeTrue();
});

it('rebuilds the catalog when the cached payload is in an unreadable shape', function (): void {
    Permission::findOrCreate('auth.users.view');

    // Simulates a payload from an older release, or one that came back as an
    // incomplete class under the host's unserialize policy.
    Cache::put(config('permissions.cache.key'), 'not-a-row-list', 300);

    $registrar = app(PermissionRegistrar::class);
    $registrar->flushMemo();

    expect($registrar->permissionExists('auth.users.view'))->toBeTrue()
        ->and(Cache::get(config('permissions.cache.key')))->toBeArray();
});
