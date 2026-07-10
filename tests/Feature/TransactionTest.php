<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    Role::findOrCreate('administrator');
    Role::findOrCreate('editor');
});

function assertWrappedInTransaction(Closure $operation): void
{
    $began = false;
    Event::listen(TransactionBeginning::class, function () use (&$began): void {
        $began = true;
    });

    $operation();

    expect($began)->toBeTrue();
}

it('wraps an additive permission grant in a transaction', function (): void {
    $role = Role::findOrCreate('administrator');

    assertWrappedInTransaction(fn () => $role->givePermissionTo('auth.users.view'));

    expect($role->getPermissionNames()->all())->toBe(['auth.users.view']);
});

it('wraps an authoritative permission sync in a transaction', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view');

    assertWrappedInTransaction(fn () => $role->syncPermissions(['auth.users.edit']));

    expect($role->getPermissionNames()->all())->toBe(['auth.users.edit']);
});

it('wraps a permission revoke in a transaction', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view', 'auth.users.edit');

    assertWrappedInTransaction(fn () => $role->revokePermissionTo('auth.users.view'));

    expect($role->getPermissionNames()->all())->toBe(['auth.users.edit']);
});

it('wraps a role sync in a transaction', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');

    assertWrappedInTransaction(fn () => $user->syncRoles(['editor']));

    expect($user->getRoleNames()->all())->toBe(['editor']);
});
