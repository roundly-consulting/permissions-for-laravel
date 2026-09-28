<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

it('creates roles and permissions as the configured model', function (): void {
    // The README's documented entry point, called on the packaged class — a host
    // that swapped the model must still get *its* class back.
    $role = Role::findOrCreate('editor');
    $permission = Permission::findOrCreate('posts.edit');

    expect($role)->toBeInstanceOf(CustomRole::class)
        ->and($permission)->toBeInstanceOf(CustomPermission::class);

    // Eloquent keys model events by the concrete class, so this is the proof the
    // row was really created as the host's model and not merely cast to it.
    expect(CustomRole::$creationCount)->toBe(1)
        ->and(CustomPermission::$creationCount)->toBe(1);
});

it('invalidates the catalog cache when a permission is registered', function (): void {
    // Warm the catalog the way any earlier gate check would.
    Permissions::permissions();

    Permission::findOrCreate('posts.edit');

    // The provider's invalidation listener lives on the *configured* model. If
    // findOrCreate created a packaged Permission, this stays stale for the whole
    // cache TTL and the Gate hook answers "not one of ours" for a real permission.
    expect(Permissions::exists('posts.edit'))->toBeTrue()
        ->and(Permissions::permissions()->pluck('name')->all())->toBe(['posts.edit']);
});

it('authorizes through the gate immediately after registering a permission', function (): void {
    Permissions::permissions();

    $role = Role::findOrCreate('editor');
    $role->givePermissionTo(Permission::findOrCreate('posts.edit')->name);

    $user = User::query()->create(['name' => 'ada']);
    $user->assignRole('editor');

    expect($user->can('posts.edit'))->toBeTrue();
});

it('drives the whole package through the host subclasses', function (): void {
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.edit', 'posts.publish');
})->throws(PermissionDoesNotExist::class);

it('resolves roles, grants and effective permissions on the configured models', function (): void {
    Permission::findOrCreate('posts.edit');
    Permission::findOrCreate('posts.publish');
    Permission::findOrCreate('posts.delete');

    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.edit', 'posts.publish');

    $user = User::query()->create(['name' => 'ada']);
    $user->assignRole('editor');
    $user->givePermissionTo('posts.delete');

    expect($user->roles->first())->toBeInstanceOf(CustomRole::class)
        ->and($user->permissions->first())->toBeInstanceOf(CustomPermission::class)
        ->and($user->getRoleNames()->all())->toBe(['editor'])
        ->and($user->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['posts.delete', 'posts.edit', 'posts.publish'])
        ->and($user->getDirectPermissions()->pluck('name')->all())->toBe(['posts.delete'])
        ->and($user->hasPermissionTo('posts.publish'))->toBeTrue()
        ->and($user->can('posts.publish'))->toBeTrue();

    // The role's own permission relation resolves to the configured model too.
    expect($role->permissions->first())->toBeInstanceOf(CustomPermission::class)
        ->and($role->refresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['posts.edit', 'posts.publish']);

    // …and the inverse.
    expect(CustomPermission::query()->where('name', 'posts.edit')->first()?->roles->first())
        ->toBeInstanceOf(CustomRole::class);
});

it('scopes a query by role on the configured model', function (): void {
    Role::findOrCreate('editor');

    $editor = User::query()->create(['name' => 'ada']);
    $editor->assignRole('editor');
    User::query()->create(['name' => 'bob']);

    expect(User::query()->role('editor')->pluck('name')->all())->toBe(['ada']);
});

it('registers, finds, lists and syncs through the facade as the configured models', function (): void {
    $role = Permissions::role('editor');
    $permission = Permissions::permission('posts.edit');
    $synced = Permissions::syncRolesFrom(RoleName::class);

    expect($role)->toBeInstanceOf(CustomRole::class)
        ->and($permission)->toBeInstanceOf(CustomPermission::class)
        ->and(Permissions::roleModel())->toBe(CustomRole::class)
        ->and(Permissions::permissionModel())->toBe(CustomPermission::class)
        ->and(Permissions::findRole('editor'))->toBeInstanceOf(CustomRole::class)
        ->and(Permissions::findPermission('posts.edit'))->toBeInstanceOf(CustomPermission::class)
        ->and(Permissions::roles()->every(fn ($role): bool => $role instanceof CustomRole))->toBeTrue()
        ->and($synced->created)->toBe(['administrator'])
        ->and($synced->existing)->toBe(['editor'])
        // One row per findOrCreate + one for the synced case: every row went through the
        // host's model, so its events fired.
        ->and(CustomRole::$creationCount)->toBe(2)
        ->and(CustomPermission::$creationCount)->toBe(1);
});
