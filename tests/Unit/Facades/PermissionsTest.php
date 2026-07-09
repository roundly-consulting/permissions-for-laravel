<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

it('proxies the registrar through the facade', function (): void {
    Permission::findOrCreate('auth.users.view');

    expect(Permissions::permissionExists('auth.users.view'))->toBeTrue()
        ->and(Permissions::permissionExists('auth.users.edit'))->toBeFalse()
        ->and(Permissions::getPermissions()->pluck('name')->all())->toBe(['auth.users.view']);
});

it('forgets the cache through the facade', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();

    Permissions::forgetCachedPermissions();

    $memo = (new ReflectionProperty($registrar, 'permissions'))->getValue($registrar);

    expect($memo)->toBeNull();
});
