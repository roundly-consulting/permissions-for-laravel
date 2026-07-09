<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    Permission::findOrCreate('auth.users.delete');
    Role::findOrCreate('administrator');
    Role::findOrCreate('editor');
});

it('assigns and lists roles', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->assignRole('administrator', RoleName::Editor);

    expect($user->getRoleNames()->sort()->values()->all())->toBe(['administrator', 'editor']);
});

it('assigns without detaching existing roles', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');
    $user->assignRole('editor');

    expect($user->getRoleNames()->sort()->values()->all())->toBe(['administrator', 'editor']);
});

it('removes a role', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator', 'editor');

    $user->removeRole('editor');

    expect($user->getRoleNames()->all())->toBe(['administrator']);
});

it('syncs roles to an authoritative set', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator', 'editor');

    $user->syncRoles(['editor']);

    expect($user->getRoleNames()->all())->toBe(['editor']);
});

it('checks role membership by string, enum and iterable', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');

    expect($user->hasRole('administrator'))->toBeTrue()
        ->and($user->hasRole(RoleName::Administrator))->toBeTrue()
        ->and($user->hasRole(['editor', 'administrator']))->toBeTrue()
        ->and($user->hasRole('editor'))->toBeFalse();
});

it('throws when assigning an unknown role', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->assignRole('superhero');
})->throws(RoleDoesNotExist::class, 'There is no role named "superhero".');

it('excludes role-derived permissions from direct permissions', function (): void {
    $user = User::create(['name' => 'Ada']);
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view');

    $user->assignRole('administrator');
    $user->givePermissionTo('auth.users.edit');

    expect($user->getDirectPermissions()->pluck('name')->all())->toBe(['auth.users.edit']);
});

it('unions direct and role permissions, de-duped', function (): void {
    $user = User::create(['name' => 'Ada']);
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view', 'auth.users.edit');

    $user->assignRole('administrator');
    $user->givePermissionTo('auth.users.view'); // also granted via role

    expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['auth.users.edit', 'auth.users.view']);
});

it('resolves effective permission checks direct or via role', function (): void {
    $user = User::create(['name' => 'Ada']);
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.view');
    $user->assignRole('administrator');
    $user->givePermissionTo('auth.users.edit');

    expect($user->hasPermissionTo('auth.users.view'))->toBeTrue()
        ->and($user->hasPermissionTo(PermissionName::EditUsers))->toBeTrue()
        ->and($user->hasPermissionTo('auth.users.delete'))->toBeFalse();
});

it('counts holders of a role via the query scope', function (): void {
    $admin = User::create(['name' => 'Ada']);
    $editor = User::create(['name' => 'Grace']);
    $admin->assignRole('administrator');
    $editor->assignRole('editor');

    expect(User::query()->role('administrator')->count())->toBe(1)
        ->and(User::query()->role(RoleName::Editor)->count())->toBe(1)
        ->and(User::query()->role(['administrator', 'editor'])->count())->toBe(2);
});

it('honours a custom morph map alias in the pivot', function (): void {
    Relation::morphMap(['user' => User::class]);

    try {
        $user = User::create(['name' => 'Ada']);
        $user->assignRole('administrator');

        expect(DB::table('model_roles')->value('model_type'))->toBe('user')
            ->and($user->fresh()->hasRole('administrator'))->toBeTrue();
    } finally {
        Relation::morphMap([], false);
    }
});
