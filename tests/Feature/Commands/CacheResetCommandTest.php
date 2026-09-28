<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

it('flushes the permission cache and exits successfully', function (): void {
    Permission::findOrCreate('auth.users.view');
    $registrar = app(PermissionRegistrar::class);
    $registrar->permissions();

    $this->artisan('permissions:cache-reset')
        ->assertExitCode(0);

    $memo = (new ReflectionProperty($registrar, 'permissions'))->getValue($registrar);

    expect($memo)->toBeNull();
});
