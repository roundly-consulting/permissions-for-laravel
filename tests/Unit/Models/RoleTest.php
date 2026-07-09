<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;

it('creates a role and returns the configured concrete model', function (): void {
    $role = Role::findOrCreate('administrator');

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->name)->toBe('administrator');
});

it('is idempotent under the unique name index', function (): void {
    $first = Role::findOrCreate('administrator');
    $second = Role::findOrCreate('administrator');

    expect($second->getKey())->toBe($first->getKey())
        ->and(Role::query()->count())->toBe(1);
});

it('normalizes a backed enum name to its value', function (): void {
    $role = Role::findOrCreate(RoleName::Administrator);

    expect($role->name)->toBe('administrator');
});

it('round-trips the description locale map', function (): void {
    $role = Role::factory()->create([
        'description' => ['en' => 'Administrator', 'sk' => 'Administrátor'],
    ]);

    expect($role->fresh()->description)->toBe(['en' => 'Administrator', 'sk' => 'Administrátor']);
});

it('resolves its table name from config', function (): void {
    expect((new Role)->getTable())->toBe('roles');

    config()->set('permissions.table_names.roles', 'custom_roles');

    expect((new Role)->getTable())->toBe('custom_roles');
});

it('cascades pivot rows on delete but leaves permissions intact', function (): void {
    $role = Role::findOrCreate('administrator');
    $permission = Permission::findOrCreate('auth.users.view');
    $role->givePermissionTo($permission);

    expect(Schema::hasTable('permission_role'))->toBeTrue();

    $role->delete();

    expect(Permission::query()->whereKey($permission->getKey())->exists())->toBeTrue()
        ->and(
            $permission->roles()->count()
        )->toBe(0);
});
