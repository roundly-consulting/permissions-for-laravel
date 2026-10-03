<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;

it('memoizes the catalog so a second lookup runs no query', function (): void {
    Permission::findOrCreate('auth.users.view');

    $registrar = app(PermissionRegistrar::class);
    $registrar->forget();

    DB::enableQueryLog();
    $registrar->permissions();
    $afterFirst = count(DB::getQueryLog());
    $registrar->permissions();
    $afterSecond = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($afterFirst)->toBe(1)
        ->and($afterSecond)->toBe(1);
});

it('serves a fresh registrar from the store without a query', function (): void {
    Permission::findOrCreate('auth.users.view');

    app(PermissionRegistrar::class)->permissions();

    $fresh = new PermissionRegistrar(app('cache'));

    DB::enableQueryLog();
    $permissions = $fresh->permissions();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($permissions->pluck('name')->all())->toBe(['auth.users.view']);
});

it('reports whether a permission exists by string or enum', function (): void {
    Permission::findOrCreate('auth.users.view');

    $registrar = app(PermissionRegistrar::class);

    expect($registrar->exists('auth.users.view'))->toBeTrue()
        ->and($registrar->exists(PermissionName::ViewUsers))->toBeTrue()
        ->and($registrar->exists('auth.users.delete'))->toBeFalse();
});

it('forgets the store and memo on invalidation', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    Permission::query()->getConnection()->table('permissions')->insert([
        'name' => 'auth.users.edit',
    ]);

    // Still memoized — the raw insert bypassed model events.
    expect($registrar->exists('auth.users.edit'))->toBeFalse();

    $registrar->forget();

    expect($registrar->exists('auth.users.edit'))->toBeTrue();
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

it('throws on junk cache config instead of using the defaults (strict config)', function (string $key, mixed $junk, string $message): void {
    Permission::findOrCreate('auth.users.view');

    config()->set($key, $junk);

    expect(fn () => (new PermissionRegistrar(app('cache')))->permissions())
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'store not a string' => ['permissions.cache.store', 123, 'permissions.cache.store'],
    'key not a string' => ['permissions.cache.key', ['not', 'a', 'string'], 'permissions.cache.key'],
    'ttl junk' => ['permissions.cache.ttl', 'forever', 'permissions.cache.ttl'],
    'ttl float string' => ['permissions.cache.ttl', '1.5', 'permissions.cache.ttl'],
    'ttl zero' => ['permissions.cache.ttl', 0, 'permissions.cache.ttl'],
]);

it('reads env-string ttls and defaults absent cache config (strict config)', function (): void {
    config()->set('permissions.cache.ttl', '600');
    expect(PermissionRegistrar::cacheTtl())->toBe(600);

    config()->set('permissions.cache.ttl', null);
    config()->set('permissions.cache.store', null);
    config()->set('permissions.cache.key', null);

    expect(PermissionRegistrar::cacheTtl())->toBe(300)
        ->and(PermissionRegistrar::cacheStoreName())->toBeNull()
        ->and(PermissionRegistrar::cacheKey())->toBe('permissions.cache');

    config()->set('permissions.cache.store', 'redis');
    expect(PermissionRegistrar::cacheStoreName())->toBe('redis');
});

it('reads blank cache config as not set, so the defaults apply (strict config)', function (string $blank): void {
    config()->set('permissions.cache.ttl', $blank);
    config()->set('permissions.cache.store', $blank);
    config()->set('permissions.cache.key', $blank);

    expect(PermissionRegistrar::cacheTtl())->toBe(300)
        ->and(PermissionRegistrar::cacheStoreName())->toBeNull()
        ->and(PermissionRegistrar::cacheKey())->toBe('permissions.cache');
})->with(['empty' => [''], 'whitespace' => ['  ']]);

it('throws on a non-string table name (strict config)', function (mixed $junk): void {
    config()->set('permissions.table_names.roles', $junk);

    PermissionRegistrar::rolesTable();
})->with([
    'array' => [['roles']],
    'int' => [1],
])->throws(InvalidConfigurationException::class, 'permissions.table_names.roles');

it('uses the default table name when the key is not set (strict config)', function (mixed $unset): void {
    config()->set('permissions.table_names.roles', $unset);

    expect(PermissionRegistrar::rolesTable())->toBe('roles');
})->with(['null' => [null], 'blank' => [''], 'whitespace' => ['  ']]);

it('hands the raw cache ttl env string to the strict reader (strict config)', function (): void {
    $_SERVER['PERMISSIONS_CACHE_TTL'] = 'five';

    try {
        /** @var array{cache: array{ttl: mixed}} $config */
        $config = require __DIR__.'/../../../config/permissions.php';
    } finally {
        unset($_SERVER['PERMISSIONS_CACHE_TTL']);
    }

    config()->set('permissions.cache.ttl', $config['cache']['ttl']);

    expect($config['cache']['ttl'])->toBe('five')
        ->and(fn (): int => PermissionRegistrar::cacheTtl())->toThrow(InvalidConfigurationException::class, 'permissions.cache.ttl');
});
