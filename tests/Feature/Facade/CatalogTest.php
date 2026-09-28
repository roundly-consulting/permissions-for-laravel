<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionCache;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\PureAbility;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

it('finds or creates a role through the facade, idempotently', function (): void {
    $first = Permissions::role('editor');
    $again = Permissions::role(RoleName::Editor);

    expect($first)->toBeInstanceOf(Role::class)
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($again->is($first))->toBeTrue()
        ->and(Role::query()->count())->toBe(1);
});

it('finds or creates a permission through the facade, idempotently', function (): void {
    $first = Permissions::permission(PermissionName::ViewUsers);
    $again = Permissions::permission('auth.users.view');

    expect($first)->toBeInstanceOf(Permission::class)
        ->and($first->name)->toBe('auth.users.view')
        ->and($again->is($first))->toBeTrue()
        ->and(Permission::query()->count())->toBe(1);
});

it('finds a role or permission by name or enum, or null', function (): void {
    $role = Permissions::role('editor');
    $permission = Permissions::permission('auth.users.view');
    $permission->update(['description' => ['en' => 'View users']]);

    expect(Permissions::findRole(RoleName::Editor)?->is($role))->toBeTrue()
        ->and(Permissions::findRole('ghost'))->toBeNull()
        ->and(Permissions::findPermission(PermissionName::ViewUsers)?->description)->toBe('View users')
        ->and(Permissions::findPermission('ghost'))->toBeNull();
});

it('lists every role ordered by name', function (): void {
    Permissions::role('editor');
    Permissions::role('administrator');

    expect(Permissions::roles()->pluck('name')->all())->toBe(['administrator', 'editor']);
});

it('lists the cached permission catalog and answers exists()', function (): void {
    Permissions::permission('auth.users.view');

    expect(Permissions::permissions()->pluck('name')->all())->toBe(['auth.users.view'])
        ->and(Permissions::exists('auth.users.view'))->toBeTrue()
        ->and(Permissions::exists(PermissionName::ViewUsers))->toBeTrue()
        ->and(Permissions::exists('auth.users.edit'))->toBeFalse();
});

it('names the configured model classes', function (): void {
    expect(Permissions::roleModel())->toBe(Role::class)
        ->and(Permissions::permissionModel())->toBe(Permission::class);
});

it('syncs permissions from a backed enum, additively', function (): void {
    Permissions::permission('auth.users.edit');
    Permissions::permission('billing.view'); // registered by someone else — must survive

    $result = Permissions::syncFrom(PermissionName::class);

    expect($result)->toBeInstanceOf(SyncResult::class)
        ->and($result->created)->toBe(['auth.users.view'])
        ->and($result->existing)->toBe(['auth.users.edit'])
        ->and($result->changed())->toBeTrue()
        ->and(Permission::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['auth.users.edit', 'auth.users.view', 'billing.view'])
        ->and(Permissions::exists(PermissionName::ViewUsers))->toBeTrue();

    $again = Permissions::syncFrom(PermissionName::class);

    expect($again->created)->toBe([])
        ->and($again->existing)->toBe(['auth.users.view', 'auth.users.edit'])
        ->and($again->changed())->toBeFalse();
});

it('syncs roles from a backed enum', function (): void {
    $result = Permissions::syncRolesFrom(RoleName::class);

    expect($result->created)->toBe(['administrator', 'editor'])
        ->and($result->existing)->toBe([])
        ->and(Permissions::roles()->pluck('name')->all())->toBe(['administrator', 'editor'])
        ->and(Permission::query()->count())->toBe(0);
});

it('refuses to sync from anything but a backed enum', function (string $class): void {
    Permissions::syncFrom($class);
})->with([
    'a pure enum' => PureAbility::class,
    'a class' => User::class,
    'nothing' => 'App\\Enums\\Missing',
])->throws(PermissionException::class, 'is not a backed enum');

it('prunes orphaned grants through the facade', function (): void {
    Permissions::role('editor');
    $kept = User::create(['name' => 'Kept']);
    $gone = User::create(['name' => 'Gone']);
    $kept->assignRole('editor');
    $gone->assignRole('editor');

    DB::table('users')->where('id', $gone->getKey())->delete();

    expect(Permissions::pruneOrphans())->toBe(1)
        ->and(DB::table('model_roles')->pluck('model_id')->all())->toBe([$kept->getKey()])
        ->and(Permissions::pruneOrphans())->toBe(0);
});

it('forgets the catalog cache through the cache sub-accessor', function (): void {
    Permissions::permission('auth.users.view');
    expect(Permissions::exists('auth.users.edit'))->toBeFalse();

    // A bulk insert bypasses model events, so the cached catalog goes stale…
    Permission::query()->getConnection()->table('permissions')->insert(['name' => 'auth.users.edit']);
    expect(Permissions::exists('auth.users.edit'))->toBeFalse()
        ->and(Permissions::cache())->toBeInstanceOf(PermissionCache::class);

    // …until it is forgotten.
    Permissions::cache()->forget();

    expect(Permissions::exists('auth.users.edit'))->toBeTrue();
});

it('flushes only the memo through the cache sub-accessor', function (): void {
    Permissions::permission('auth.users.view');
    Permissions::permissions();
    $registrar = app(PermissionRegistrar::class);

    Permissions::cache()->flushMemo();

    expect((new ReflectionProperty($registrar, 'permissions'))->getValue($registrar))->toBeNull();

    // The shared store still holds the catalog: the next read runs no query.
    DB::enableQueryLog();
    Permissions::permissions();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('serves the same API from an injected manager', function (): void {
    $manager = app(PermissionsManager::class);

    $role = $manager->role('editor');
    $manager->permission('auth.users.view');
    $manager->for($role)->givePermissionTo('auth.users.view');

    expect($manager)->toBe(app(PermissionsManager::class))
        ->and($manager->findRole('editor')?->permissions->pluck('name')->all())->toBe(['auth.users.view'])
        ->and($manager->exists('auth.users.view'))->toBeTrue();
});
