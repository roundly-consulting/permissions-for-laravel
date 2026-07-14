<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Permissions\Models\Permission;

/**
 * The single resolution point for the model configured at `permissions.models.permission`.
 *
 * Wraps the toolkit's {@see ModelResolver} (which validates the configured value is a
 * real Eloquent model) and narrows the result to this package's own base class — the
 * package calls `Permission`'s own API (`roles()`, `name`, `description()`), so a real
 * model that is not a `Permission` falls back to the packaged one rather than fataling later.
 */
final class PermissionModel
{
    /** @return class-string<Permission> */
    public static function class(): string
    {
        $model = ModelResolver::for('permissions.models.permission', Permission::class);

        return is_a($model, Permission::class, true) ? $model : Permission::class;
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
