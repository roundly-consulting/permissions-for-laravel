<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;

beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    Permission::findOrCreate('auth.users.delete');
});

it('grants additively without stripping existing grants', function (): void {
    $role = Role::findOrCreate('administrator');

    $role->givePermissionTo('auth.users.view');
    $role->givePermissionTo('auth.users.edit');

    expect($role->getPermissionNames()->sort()->values()->all())
        ->toBe(['auth.users.edit', 'auth.users.view']);
});

it('syncs to the exact authoritative set', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view', 'auth.users.edit');

    $role->syncPermissions(['auth.users.delete']);

    expect($role->getPermissionNames()->all())->toBe(['auth.users.delete']);
});

it('revokes a permission', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view', 'auth.users.edit');

    $role->revokePermissionTo('auth.users.view');

    expect($role->getPermissionNames()->all())->toBe(['auth.users.edit']);
});

it('accepts names, enums, models and iterables', function (): void {
    $role = Role::findOrCreate('administrator');
    $delete = Permission::findOrCreate('auth.users.delete');

    $role->givePermissionTo(PermissionName::ViewUsers, $delete, ['auth.users.edit']);

    expect($role->getPermissionNames()->sort()->values()->all())
        ->toBe(['auth.users.delete', 'auth.users.edit', 'auth.users.view']);
});

it('reports direct permission membership', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view');

    expect($role->hasPermissionTo('auth.users.view'))->toBeTrue()
        ->and($role->hasPermissionTo(PermissionName::ViewUsers))->toBeTrue()
        ->and($role->hasPermissionTo(Permission::findOrCreate('auth.users.view')))->toBeTrue()
        ->and($role->hasPermissionTo('auth.users.edit'))->toBeFalse();
});

it('throws when granting an unknown permission name', function (): void {
    $role = Role::findOrCreate('administrator');

    $role->givePermissionTo('auth.users.unknown');
})->throws(PermissionDoesNotExist::class, 'There is no permission named "auth.users.unknown".');

it('throws when a permission argument is an unsupported type', function (): void {
    $role = Role::findOrCreate('administrator');

    $role->givePermissionTo([123]);
})->throws(PermissionException::class, 'Permissions must be strings, backed enums, or Permission models.');

it('forgets the cache on every grant mutator', function (): void {
    $role = Role::findOrCreate('administrator');
    $registrar = app(PermissionRegistrar::class);

    $registrar->permissions(); // warm
    $role->givePermissionTo('auth.users.view');

    $reflection = new ReflectionProperty($registrar, 'permissions');

    expect($reflection->getValue($registrar))->toBeNull();
});
