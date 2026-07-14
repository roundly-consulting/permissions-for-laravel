<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * The single resolution point for the model configured at `permissions.models.role`.
 *
 * Wraps the toolkit's {@see ModelResolver} (which validates the configured value is a
 * real Eloquent model) and narrows the result to this package's own base class — the
 * package calls `Role`'s own API (`permissions()`, `name`, the grant traits), so a real
 * model that is not a `Role` falls back to the packaged one rather than fataling later.
 */
final class RoleModel
{
    /** @return class-string<Role> */
    public static function class(): string
    {
        $model = ModelResolver::for('permissions.models.role', Role::class);

        return is_a($model, Role::class, true) ? $model : Role::class;
    }

    public static function new(): Role
    {
        $class = self::class();

        return new $class;
    }

    /** @return Builder<Role> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
