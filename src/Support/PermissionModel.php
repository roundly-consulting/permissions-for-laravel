<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Permissions\Models\Permission;

/**
 * The single resolution point for the model configured at `permissions.models.permission`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 *
 * @internal Host code uses `Permissions::permissionModel()`, `Permissions::permissions()` and friends.
 */
final class PermissionModel
{
    /** @return class-string<Permission> */
    public static function class(): string
    {
        return ModelResolver::for('permissions.models.permission', Permission::class);
    }

    public static function new(): Permission
    {
        $class = self::class();

        return new $class;
    }

    /** @return Builder<Permission> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
