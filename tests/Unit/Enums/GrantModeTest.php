<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

it('exposes both grant modes with backed values', function (): void {
    expect(GrantMode::Additive->value)->toBe('additive')
        ->and(GrantMode::Authoritative->value)->toBe('authoritative')
        ->and(GrantMode::values()->all())->toBe(['additive', 'authoritative']);
});

it('reports which mode detaches', function (): void {
    expect(GrantMode::Additive->detaches())->toBeFalse()
        ->and(GrantMode::Authoritative->detaches())->toBeTrue();
});

it('surfaces enums-for-laravel convention helpers', function (): void {
    expect(GrantMode::tryFromName('Additive'))->toBe(GrantMode::Additive)
        ->and(GrantMode::labels()->all())->toBe(['Additive', 'Authoritative']);
});

it('drives additive versus authoritative pivot behaviour through a grant', function (): void {
    Permission::findOrCreate('a');
    Permission::findOrCreate('b');
    $role = Role::findOrCreate('administrator');

    $role->givePermissionTo('a'); // GrantMode::Additive
    $role->givePermissionTo('b');
    expect($role->getPermissionNames()->sort()->values()->all())->toBe(['a', 'b']);

    $role->syncPermissions(['a']); // GrantMode::Authoritative
    expect($role->getPermissionNames()->all())->toBe(['a']);
});
