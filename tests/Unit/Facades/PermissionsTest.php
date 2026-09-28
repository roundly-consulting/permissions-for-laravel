<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

it('answers catalog reads from the registrar behind the facade', function (): void {
    Permission::findOrCreate('auth.users.view');

    expect(Permissions::exists('auth.users.view'))->toBeTrue()
        ->and(Permissions::exists('auth.users.edit'))->toBeFalse()
        ->and(Permissions::permissions()->pluck('name')->all())->toBe(['auth.users.view'])
        ->and(Permissions::permissions())->toBe(app(PermissionRegistrar::class)->permissions());
});

it('forgets the cache through the facade', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    Permissions::cache()->forget();

    $memo = (new ReflectionProperty($registrar, 'permissions'))->getValue($registrar);

    expect($memo)->toBeNull();
});
