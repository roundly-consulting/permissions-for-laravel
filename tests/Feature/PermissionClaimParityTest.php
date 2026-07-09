<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

/**
 * The JWT `permissions` claim is `getAllPermissions()->pluck('name')` treated as
 * a set. It must be identical whether the relations are eager- or lazy-loaded.
 */
beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    Permission::findOrCreate('auth.users.delete');

    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view', 'auth.users.edit');
});

function sortedClaim(User $user): array
{
    return $user->getAllPermissions()->pluck('name')->sort()->values()->all();
}

it('produces the same sorted permission set eager or lazy', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');
    $user->givePermissionTo('auth.users.view', 'auth.users.delete');

    $lazy = sortedClaim($user->fresh());
    $eager = sortedClaim(User::query()->with('roles.permissions', 'permissions')->find($user->getKey()));

    expect($lazy)->toBe(['auth.users.delete', 'auth.users.edit', 'auth.users.view'])
        ->and($eager)->toBe($lazy);
});

it('returns an empty set for a user with no grants', function (): void {
    $user = User::create(['name' => 'Grace']);

    expect(sortedClaim($user))->toBe([]);
});
