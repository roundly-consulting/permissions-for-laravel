<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Actions\FindOrCreatePermission;
use RoundlyConsulting\Permissions\Actions\FindOrCreateRole;
use RoundlyConsulting\Permissions\Actions\ForgetAuthorization;
use RoundlyConsulting\Permissions\Actions\GrantPermissions;
use RoundlyConsulting\Permissions\Actions\GrantRoles;
use RoundlyConsulting\Permissions\Actions\PruneOrphans;
use RoundlyConsulting\Permissions\Actions\RemoveRoles;
use RoundlyConsulting\Permissions\Actions\RevokePermissions;
use RoundlyConsulting\Permissions\Actions\SyncFromEnum;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

/**
 * The raw action form — for DI purists, queued jobs and host actions composing ours. Each
 * action is resolved from the container and executed directly, with no facade in between.
 */
beforeEach(function (): void {
    $this->user = User::create(['name' => 'Ada']);
});

it('finds or creates a role', function (): void {
    $role = app(FindOrCreateRole::class)->execute(RoleName::Editor);

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->name)->toBe('editor')
        ->and(app(FindOrCreateRole::class)->execute('editor')->is($role))->toBeTrue();
});

it('finds or creates a permission', function (): void {
    $permission = app(FindOrCreatePermission::class)->execute(PermissionName::ViewUsers);

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->name)->toBe('auth.users.view')
        ->and(app(FindOrCreatePermission::class)->execute('auth.users.view')->is($permission))->toBeTrue();
});

it('grants roles additively or authoritatively', function (): void {
    Role::findOrCreate('editor');
    Role::findOrCreate('administrator');

    app(GrantRoles::class)->execute($this->user, ['editor'], GrantMode::Additive);
    app(GrantRoles::class)->execute($this->user, [RoleName::Administrator], GrantMode::Additive);

    expect($this->user->getRoleNames()->sort()->values()->all())->toBe(['administrator', 'editor']);

    app(GrantRoles::class)->execute($this->user, ['editor'], GrantMode::Authoritative);

    expect($this->user->getRoleNames()->all())->toBe(['editor']);
});

it('removes roles', function (): void {
    Role::findOrCreate('editor');
    $this->user->assignRole('editor');

    app(RemoveRoles::class)->execute($this->user, [RoleName::Editor]);

    expect($this->user->getRoleNames()->all())->toBe([]);
});

it('grants permissions additively or authoritatively', function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    $role = Role::findOrCreate('editor');

    app(GrantPermissions::class)->execute($role, [PermissionName::ViewUsers], GrantMode::Additive);
    app(GrantPermissions::class)->execute($role, ['auth.users.edit'], GrantMode::Additive);

    expect($role->getPermissionNames()->sort()->values()->all())->toBe(['auth.users.edit', 'auth.users.view']);

    app(GrantPermissions::class)->execute($role, [], GrantMode::Authoritative);

    expect($role->getPermissionNames()->all())->toBe([]);
});

it('revokes permissions', function (): void {
    Permission::findOrCreate('auth.users.view');
    $this->user->givePermissionTo('auth.users.view');

    app(RevokePermissions::class)->execute($this->user, [PermissionName::ViewUsers]);

    expect($this->user->getPermissionNames()->all())->toBe([]);
});

it('forgets every grant of a holder', function (): void {
    Role::findOrCreate('editor');
    Permission::findOrCreate('auth.users.view');
    $this->user->assignRole('editor')->givePermissionTo('auth.users.view');

    app(ForgetAuthorization::class)->execute($this->user);

    expect(DB::table('model_roles')->count())->toBe(0)
        ->and(DB::table('model_permissions')->count())->toBe(0);
});

it('syncs catalog rows from a backed enum as the given model', function (): void {
    $result = app(SyncFromEnum::class)->execute(RoleName::class, Role::class);

    expect($result->created)->toBe(['administrator', 'editor'])
        ->and(Role::query()->count())->toBe(2)
        ->and(Permission::query()->count())->toBe(0);
});

it('prunes orphaned pivot rows', function (): void {
    Role::findOrCreate('editor');
    $this->user->assignRole('editor');
    DB::table('users')->delete();

    expect(app(PruneOrphans::class)->execute())->toBe(1)
        ->and(DB::table('model_roles')->count())->toBe(0);
});
