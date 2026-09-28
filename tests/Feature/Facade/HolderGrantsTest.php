<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\Post;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permissions::syncRolesFrom(RoleName::class);
    Permissions::syncFrom(PermissionName::class);
});

it('assigns, removes and syncs roles through the facade', function (): void {
    $user = User::create(['name' => 'Ada']);

    $returned = Permissions::for($user)->assignRole('administrator', RoleName::Editor);

    expect($returned)->toBe($user)
        ->and($user->getRoleNames()->sort()->values()->all())->toBe(['administrator', 'editor']);

    Permissions::for($user)->removeRole('administrator');

    expect($user->getRoleNames()->all())->toBe(['editor']);

    Permissions::for($user)->syncRoles([RoleName::Administrator]);

    expect($user->getRoleNames()->all())->toBe(['administrator'])
        ->and($user->can('auth.users.view'))->toBeFalse();
});

it('gives, revokes and syncs direct permissions through the facade', function (): void {
    $user = User::create(['name' => 'Ada']);

    Permissions::for($user)->givePermissionTo(PermissionName::ViewUsers, 'auth.users.edit');

    expect($user->getPermissionNames()->sort()->values()->all())->toBe(['auth.users.edit', 'auth.users.view'])
        ->and($user->can('auth.users.view'))->toBeTrue();

    Permissions::for($user)->revokePermissionTo('auth.users.edit');

    expect($user->getPermissionNames()->all())->toBe(['auth.users.view']);

    Permissions::for($user)->syncPermissions(['auth.users.edit']);

    expect($user->getPermissionNames()->all())->toBe(['auth.users.edit']);
});

it('grants permissions to a role through the facade, and holders inherit them', function (): void {
    $role = Permissions::findRole('editor');
    $user = User::create(['name' => 'Ada']);
    $user->assignRole($role);

    expect(Permissions::for($role)->givePermissionTo('auth.users.view'))->toBe($role);

    expect($user->fresh()?->hasPermissionTo(PermissionName::ViewUsers))->toBeTrue();
});

it('forgets all authorization through the facade', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('editor');
    $user->givePermissionTo('auth.users.view');

    Permissions::for($user)->forgetAllAuthorization();

    expect(DB::table('model_roles')->count())->toBe(0)
        ->and(DB::table('model_permissions')->count())->toBe(0)
        ->and($user->getRoleNames()->all())->toBe([]);
});

it('forgets authorization from a deleted hook, where the holder no longer exists', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('editor');
    $user->delete();

    expect($user->exists)->toBeFalse();

    Permissions::for($user)->forgetAllAuthorization();

    expect(DB::table('model_roles')->count())->toBe(0);
});

/**
 * The `for()` scope is a boundary: a Role holds permissions, never roles, so every role write
 * scoped to one is refused — with the package's exception, before any pivot is touched.
 */
it('refuses role writes on a holder that cannot hold roles', function (string $method, array $arguments): void {
    $role = Permissions::findRole('editor');

    try {
        Permissions::for($role)->{$method}(...$arguments);
    } finally {
        expect(DB::table('model_roles')->count())->toBe(0);
    }
})->with([
    'assignRole' => ['assignRole', ['administrator']],
    'removeRole' => ['removeRole', ['administrator']],
    'syncRoles' => ['syncRoles', [['administrator']]],
    'forgetAllAuthorization' => ['forgetAllAuthorization', []],
])->throws(PermissionException::class, 'cannot hold roles; add the HasRoles trait.');

it('refuses permission writes on a model that cannot hold permissions', function (string $method, array $arguments): void {
    $post = (new Post)->forceFill(['id' => 1]);
    $post->exists = true;

    Permissions::for($post)->{$method}(...$arguments);
})->with([
    'givePermissionTo' => ['givePermissionTo', ['auth.users.view']],
    'revokePermissionTo' => ['revokePermissionTo', ['auth.users.view']],
    'syncPermissions' => ['syncPermissions', [['auth.users.view']]],
])->throws(PermissionException::class, 'cannot hold permissions; add the HasPermissions trait.');

it('refuses writes on a holder with no key yet, before touching the pivot', function (string $method, array $arguments): void {
    try {
        Permissions::for(new User(['name' => 'Ghost']))->{$method}(...$arguments);
    } finally {
        expect(DB::table('model_roles')->count())->toBe(0)
            ->and(DB::table('model_permissions')->count())->toBe(0);
    }
})->with([
    'assignRole' => ['assignRole', ['editor']],
    'givePermissionTo' => ['givePermissionTo', ['auth.users.view']],
    'forgetAllAuthorization' => ['forgetAllAuthorization', []],
])->throws(PermissionException::class, 'Cannot change the grants of an unsaved');

it('refuses an unknown role before writing anything', function (): void {
    $user = User::create(['name' => 'Ada']);

    try {
        Permissions::for($user)->assignRole('editor', 'ghost');
    } finally {
        expect(DB::table('model_roles')->count())->toBe(0);
    }
})->throws(RoleDoesNotExist::class, 'There is no role named "ghost".');

it('refuses a grant argument of the wrong type', function (): void {
    $user = User::create(['name' => 'Ada']);

    Permissions::for($user)->assignRole([Role::query()->first(), 42]);
})->throws(PermissionException::class, 'Roles must be strings, backed enums, or Role models.');
