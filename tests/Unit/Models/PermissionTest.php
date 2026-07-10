<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;

it('creates a permission and returns the concrete model', function (): void {
    $permission = Permission::findOrCreate('auth.users.view');

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->name)->toBe('auth.users.view');
});

it('is idempotent under the unique name index', function (): void {
    $first = Permission::findOrCreate('auth.users.view');
    $second = Permission::findOrCreate('auth.users.view');

    expect($second->getKey())->toBe($first->getKey())
        ->and(Permission::query()->count())->toBe(1);
});

it('normalizes a backed enum name to its value', function (): void {
    $permission = Permission::findOrCreate(PermissionName::ViewUsers);

    expect($permission->name)->toBe('auth.users.view');
});

it('round-trips the description locale map', function (): void {
    $permission = Permission::factory()->create([
        'description' => ['en' => 'View users', 'sk' => 'Zobraziť používateľov'],
    ]);

    expect($permission->fresh()->description)->toBe(['en' => 'View users', 'sk' => 'Zobraziť používateľov']);
});

it('resolves its table name from config', function (): void {
    expect((new Permission)->getTable())->toBe('permissions');
});

it('reads the localized description with an app-locale fallback', function (): void {
    $permission = Permission::factory()->create([
        'description' => ['en' => 'View users', 'sk' => 'Zobraziť používateľov'],
    ]);

    app()->setLocale('sk');

    expect($permission->description())->toBe('Zobraziť používateľov')
        ->and($permission->description('en'))->toBe('View users')
        ->and($permission->description('de'))->toBeNull();
});

it('returns a null description when none is stored', function (): void {
    $permission = Permission::factory()->create(['description' => null]);

    expect($permission->description())->toBeNull();
});

it('exposes the inverse roles relation', function (): void {
    $role = Role::findOrCreate('administrator');
    $permission = Permission::findOrCreate('auth.users.view');
    $role->givePermissionTo($permission);

    expect($permission->roles()->pluck('name')->all())->toBe(['administrator']);
});
