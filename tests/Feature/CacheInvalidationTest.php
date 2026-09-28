<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

function catalogMemo(PermissionRegistrar $registrar): mixed
{
    return (new ReflectionProperty($registrar, 'permissions'))->getValue($registrar);
}

it('invalidates the catalog when a permission is created', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    expect(catalogMemo($registrar))->not->toBeNull();

    Permission::findOrCreate('auth.users.view');

    expect(catalogMemo($registrar))->toBeNull()
        ->and($registrar->exists('auth.users.view'))->toBeTrue();
});

it('invalidates the catalog when a permission is deleted', function (): void {
    $permission = Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    $permission->delete();

    expect(catalogMemo($registrar))->toBeNull()
        ->and($registrar->exists('auth.users.view'))->toBeFalse();
});

it('invalidates the catalog when a role is saved', function (): void {
    $registrar = app(PermissionRegistrar::class);
    Permission::findOrCreate('auth.users.view');
    $registrar->permissions();

    Role::findOrCreate('administrator');

    expect(catalogMemo($registrar))->toBeNull();
});

it('clears the store so a re-read reflects new rows', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    Permission::findOrCreate('auth.users.edit');

    expect($registrar->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['auth.users.edit', 'auth.users.view']);
});
