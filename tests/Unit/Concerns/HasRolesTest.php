<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

it('accepts a role model instance when assigning', function (): void {
    $role = Role::findOrCreate('administrator');
    $user = User::create(['name' => 'Ada']);

    $user->assignRole($role);

    expect($user->hasRole('administrator'))->toBeTrue();
});

it('is a no-op when assigning an empty iterable', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->assignRole([]);

    expect($user->getRoleNames()->all())->toBe([]);
});

it('accepts a backed enum role instance in hasRole', function (): void {
    Role::findOrCreate('administrator');
    $user = User::create(['name' => 'Ada']);
    $user->assignRole(RoleName::Administrator);

    expect($user->hasRole(RoleName::Administrator))->toBeTrue();
});

it('throws when a role argument is an unsupported type', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->assignRole([42]);
})->throws(PermissionException::class, 'Roles must be strings, backed enums, or Role models.');

it('checks a permission model instance directly', function (): void {
    Permission::findOrCreate('auth.users.view');
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view');

    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');

    $permission = Permission::findOrCreate('auth.users.view');

    expect($user->hasPermissionTo($permission))->toBeTrue();
});

it('checks role membership with a role model instance', function (): void {
    $role = Role::findOrCreate('administrator');
    $user = User::create(['name' => 'Ada']);
    $user->assignRole($role);

    expect($user->hasRole([$role]))->toBeTrue();
});

it('throws for an unsupported type when checking role membership', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->hasRole([42]);
})->throws(PermissionException::class, 'Roles must be strings, backed enums, or Role models.');
