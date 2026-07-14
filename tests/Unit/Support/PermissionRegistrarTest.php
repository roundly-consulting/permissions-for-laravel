<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;

it('memoizes the catalog so a second lookup runs no query', function (): void {
    Permission::findOrCreate('auth.users.view');

    $registrar = app(PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();

    DB::enableQueryLog();
    $registrar->getPermissions();
    $afterFirst = count(DB::getQueryLog());
    $registrar->getPermissions();
    $afterSecond = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($afterFirst)->toBe(1)
        ->and($afterSecond)->toBe(1);
});

it('serves a fresh registrar from the store without a query', function (): void {
    Permission::findOrCreate('auth.users.view');

    app(PermissionRegistrar::class)->getPermissions();

    $fresh = new PermissionRegistrar(app('cache'));

    DB::enableQueryLog();
    $permissions = $fresh->getPermissions();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($permissions->pluck('name')->all())->toBe(['auth.users.view']);
});

it('reports whether a permission exists by string or enum', function (): void {
    Permission::findOrCreate('auth.users.view');

    $registrar = app(PermissionRegistrar::class);

    expect($registrar->permissionExists('auth.users.view'))->toBeTrue()
        ->and($registrar->permissionExists(PermissionName::ViewUsers))->toBeTrue()
        ->and($registrar->permissionExists('auth.users.delete'))->toBeFalse();
});

it('forgets the store and memo on invalidation', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();

    Permission::query()->getConnection()->table('permissions')->insert([
        'name' => 'auth.users.edit',
    ]);

    // Still memoized — the raw insert bypassed model events.
    expect($registrar->permissionExists('auth.users.edit'))->toBeFalse();

    $registrar->forgetCachedPermissions();

    expect($registrar->permissionExists('auth.users.edit'))->toBeTrue();
});

it('normalizes names from strings and backed enums', function (): void {
    expect(PermissionRegistrar::nameOf('auth.users.view'))->toBe('auth.users.view')
        ->and(PermissionRegistrar::nameOf(PermissionName::ViewUsers))->toBe('auth.users.view');
});

it('resolves the role and permission models from config', function (): void {
    expect(PermissionRegistrar::roleModel())->toBe(Role::class)
        ->and(PermissionRegistrar::permissionModel())->toBe(Permission::class);

    config()->set('permissions.models.permission', CustomPermission::class);
    config()->set('permissions.models.role', CustomRole::class);

    expect(PermissionRegistrar::permissionModel())->toBe(CustomPermission::class)
        ->and(PermissionRegistrar::roleModel())->toBe(CustomRole::class);
});

it('falls back to defaults for non-string cache config', function (): void {
    Permission::findOrCreate('auth.users.view');

    config()->set('permissions.cache.store', 123);
    config()->set('permissions.cache.key', ['not', 'a', 'string']);
    config()->set('permissions.cache.ttl', 'forever');

    $registrar = new PermissionRegistrar(app('cache'));

    expect($registrar->getPermissions()->pluck('name')->all())->toBe(['auth.users.view']);
});
