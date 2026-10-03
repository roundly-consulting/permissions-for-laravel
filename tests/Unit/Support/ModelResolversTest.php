<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\Fixtures\NotAPermission;

it('resolves the packaged model when nothing is configured', function (): void {
    config()->set('permissions.models.role', null);
    config()->set('permissions.models.permission', null);

    expect(RoleModel::class())->toBe(Role::class)
        ->and(PermissionModel::class())->toBe(Permission::class);
});

it('honours a configured host subclass', function (): void {
    config()->set('permissions.models.role', CustomRole::class);
    config()->set('permissions.models.permission', CustomPermission::class);

    expect(RoleModel::class())->toBe(CustomRole::class)
        ->and(PermissionModel::class())->toBe(CustomPermission::class)
        ->and(RoleModel::new())->toBeInstanceOf(CustomRole::class)
        ->and(PermissionModel::new())->toBeInstanceOf(CustomPermission::class)
        ->and(RoleModel::query()->getModel())->toBeInstanceOf(CustomRole::class)
        ->and(PermissionModel::query()->getModel())->toBeInstanceOf(CustomPermission::class);
});

it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('permissions.models.role', NotAPermission::class);
    config()->set('permissions.models.permission', NotAPermission::class);

    expect(fn (): string => RoleModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [permissions.models.role] must be a class-string of ['.Role::class.'], ['.NotAPermission::class.'] given.',
    );
    expect(fn (): string => PermissionModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [permissions.models.permission] must be a class-string of ['.Permission::class.'], ['.NotAPermission::class.'] given.',
    );
});

it('throws when the configured role model is not a model class', function (): void {
    config()->set('permissions.models.role', 'App\\Models\\Nope');

    RoleModel::class();
})->throws(InvalidConfigurationException::class);

it('throws when the configured permission model is not a model class', function (): void {
    config()->set('permissions.models.permission', 42);

    PermissionModel::class();
})->throws(InvalidConfigurationException::class);
