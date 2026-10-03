<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * The single resolution point for the model configured at `permissions.models.role`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 *
 * @internal Host code uses `Permissions::roleModel()`, `Permissions::roles()` and friends.
 */
final class RoleModel
{
    /** @return class-string<Role> */
    public static function class(): string
    {
        return ModelResolver::for('permissions.models.role', Role::class);
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
