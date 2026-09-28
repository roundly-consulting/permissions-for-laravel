<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use BackedEnum;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;

/**
 * Idempotently register a role, returning the model configured at `permissions.models.role`.
 *
 * Resolved through the config seam rather than a hard-coded class, so a host that swapped the
 * model gets *its* class back — and the row is created as that class, which is what fires the
 * model events the provider hangs the catalog cache invalidation on. `createOrFirst` makes it
 * race-safe under the unique `name` index.
 */
final readonly class FindOrCreateRole
{
    public function execute(string|BackedEnum $name): Role
    {
        return RoleModel::query()->createOrFirst(['name' => PermissionRegistrar::nameOf($name)]);
    }
}
