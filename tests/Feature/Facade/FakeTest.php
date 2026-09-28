<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Testing\PermissionsFake;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    // Seeded before faking, so the fake starts with nothing recorded.
    Permissions::syncRolesFrom(RoleName::class);
    Permissions::syncFrom(PermissionName::class);

    $this->fake = Permissions::fake();
    $this->user = User::create(['name' => 'Ada']);
    $this->other = User::create(['name' => 'Bob']);
});

function failsAssertion(Closure $assertion): void
{
    expect($assertion)->toThrow(AssertionFailedError::class);
}

it('installs one recording fake behind the facade and the container, and still performs', function (): void {
    expect($this->fake)->toBeInstanceOf(PermissionsFake::class)
        ->and(app(PermissionsManager::class))->toBe($this->fake)
        ->and(Permissions::getFacadeRoot())->toBe($this->fake);

    $this->user->assignRole('editor');

    expect($this->user->hasRole('editor'))->toBeTrue()
        ->and(DB::table('model_roles')->count())->toBe(1);
});

it('records role registration through the facade and Role::findOrCreate()', function (): void {
    $this->fake->assertNoRoleRegistered();

    Permissions::role('auditor');
    Role::findOrCreate(RoleName::Editor);

    $this->fake->assertRoleRegistered('auditor');
    $this->fake->assertRoleRegistered(RoleName::Editor);
    failsAssertion(fn () => $this->fake->assertRoleRegistered('ghost'));
    failsAssertion(fn () => $this->fake->assertNoRoleRegistered());
});

it('records permission registration through the facade and Permission::findOrCreate()', function (): void {
    $this->fake->assertNoPermissionRegistered();

    Permissions::permission('billing.view');
    Permission::findOrCreate(PermissionName::EditUsers);

    $this->fake->assertPermissionRegistered('billing.view');
    $this->fake->assertPermissionRegistered(PermissionName::EditUsers);
    failsAssertion(fn () => $this->fake->assertPermissionRegistered('ghost'));
    failsAssertion(fn () => $this->fake->assertNoPermissionRegistered());
});

it('records enum syncs', function (): void {
    $this->fake->assertNothingSyncedFrom();

    Permissions::syncFrom(PermissionName::class);
    Permissions::syncRolesFrom(RoleName::class);

    $this->fake->assertSyncedFrom(PermissionName::class);
    $this->fake->assertSyncedFrom(RoleName::class);
    failsAssertion(fn () => $this->fake->assertSyncedFrom(User::class));
    failsAssertion(fn () => $this->fake->assertNothingSyncedFrom());
});

it('records role assignment made through the HasRoles trait', function (): void {
    $this->fake->assertNoRoleAssigned();

    $this->user->assignRole(RoleName::Editor);

    $this->fake->assertRoleAssigned($this->user);
    $this->fake->assertRoleAssigned($this->user, 'editor');
    $this->fake->assertRoleAssigned($this->user, Role::query()->where('name', 'editor')->firstOrFail());
    failsAssertion(fn () => $this->fake->assertRoleAssigned($this->user, 'administrator'));
    failsAssertion(fn () => $this->fake->assertRoleAssigned($this->other));
    failsAssertion(fn () => $this->fake->assertNoRoleAssigned());
});

it('records role removal made through the HasRoles trait', function (): void {
    $this->user->assignRole('editor');
    $this->fake->assertNoRoleRemoved();

    $this->user->removeRole('editor');

    $this->fake->assertRoleRemoved($this->user, RoleName::Editor);
    failsAssertion(fn () => $this->fake->assertRoleRemoved($this->other));
    failsAssertion(fn () => $this->fake->assertNoRoleRemoved());
});

it('records role syncs made through the HasRoles trait, matching the exact set', function (): void {
    $this->fake->assertNoRolesSynced();

    $this->user->syncRoles(['editor', RoleName::Administrator]);

    $this->fake->assertRolesSynced($this->user);
    $this->fake->assertRolesSynced($this->user, [RoleName::Administrator, 'editor']);
    failsAssertion(fn () => $this->fake->assertRolesSynced($this->user, ['editor']));
    failsAssertion(fn () => $this->fake->assertRolesSynced($this->other));
    failsAssertion(fn () => $this->fake->assertNoRolesSynced());

    // An assignment is not a sync.
    $this->fake->assertNoRoleAssigned();
});

it('records permission grants made through a Role model', function (): void {
    $role = Permissions::findRole('editor');
    $this->fake->assertNoPermissionGranted();

    $role?->givePermissionTo(PermissionName::ViewUsers);

    $this->fake->assertPermissionGranted($role);
    $this->fake->assertPermissionGranted($role, 'auth.users.view');
    failsAssertion(fn () => $this->fake->assertPermissionGranted($role, 'auth.users.edit'));
    failsAssertion(fn () => $this->fake->assertPermissionGranted($this->user));
    failsAssertion(fn () => $this->fake->assertNoPermissionGranted());
});

it('records permission revocation made through the HasPermissions trait', function (): void {
    $this->user->givePermissionTo('auth.users.view');
    $this->fake->assertNoPermissionRevoked();

    $this->user->revokePermissionTo(Permission::query()->where('name', 'auth.users.view')->firstOrFail());

    $this->fake->assertPermissionRevoked($this->user, PermissionName::ViewUsers);
    failsAssertion(fn () => $this->fake->assertPermissionRevoked($this->user, 'auth.users.edit'));
    failsAssertion(fn () => $this->fake->assertNoPermissionRevoked());
});

it('records permission syncs made through the for() handle and the trait', function (): void {
    $this->fake->assertNoPermissionsSynced();

    Permissions::for($this->user)->syncPermissions(['auth.users.view']);
    $this->other->syncPermissions([]);

    $this->fake->assertPermissionsSynced($this->user, [PermissionName::ViewUsers]);
    $this->fake->assertPermissionsSynced($this->other, []);
    failsAssertion(fn () => $this->fake->assertPermissionsSynced($this->user, ['auth.users.edit']));
    failsAssertion(fn () => $this->fake->assertNoPermissionsSynced());
});

it('records forgetAllAuthorization() made through the HasRoles trait', function (): void {
    $this->fake->assertNoAuthorizationForgotten();

    $this->user->forgetAllAuthorization();

    $this->fake->assertAuthorizationForgotten($this->user);
    failsAssertion(fn () => $this->fake->assertAuthorizationForgotten($this->other));
    failsAssertion(fn () => $this->fake->assertNoAuthorizationForgotten());
});

it('records orphan pruning through the artisan command', function (): void {
    $this->fake->assertNoOrphansPruned();
    failsAssertion(fn () => $this->fake->assertOrphansPruned());

    $this->artisan('permissions:prune-orphans')->assertExitCode(0);

    $this->fake->assertOrphansPruned();
    failsAssertion(fn () => $this->fake->assertNoOrphansPruned());
});

it('records explicit cache flushes, never the package\'s own invalidation', function (): void {
    // Every grant invalidates the catalog internally — that is housekeeping, not a call.
    $this->user->assignRole('editor');
    Permissions::role('auditor');
    $this->fake->assertCacheNotForgotten();
    failsAssertion(fn () => $this->fake->assertCacheForgotten());

    $this->artisan('permissions:cache-reset')->assertExitCode(0);

    $this->fake->assertCacheForgotten();
    failsAssertion(fn () => $this->fake->assertCacheNotForgotten());
});

it('records explicit memo flushes, never the per-request reset', function (): void {
    // Dispatched by name, as the package listens for it, so no laravel/octane dependency.
    event('Laravel\Octane\Events\RequestReceived');
    $this->fake->assertMemoNotFlushed();
    failsAssertion(fn () => $this->fake->assertMemoFlushed());

    Permissions::cache()->flushMemo();

    $this->fake->assertMemoFlushed();
    failsAssertion(fn () => $this->fake->assertMemoNotFlushed());
});

it('records nothing for a write that was refused', function (): void {
    expect(fn () => $this->user->assignRole('ghost'))->toThrow(RoleDoesNotExist::class);

    $this->fake->assertNoRoleAssigned();
});
