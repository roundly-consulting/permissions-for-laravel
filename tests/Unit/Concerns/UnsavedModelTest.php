<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

it('rejects an unsaved permission model instance', function (): void {
    $role = Role::findOrCreate('administrator');

    $role->givePermissionTo(new Permission(['name' => 'ghost']));
})->throws(PermissionException::class, 'Cannot grant an unsaved permission model; persist it before granting.');

it('rejects an unsaved role model instance', function (): void {
    $user = User::create(['name' => 'Ada']);

    $user->assignRole(new Role(['name' => 'ghost']));
})->throws(PermissionException::class, 'Cannot grant an unsaved role model; persist it before granting.');
