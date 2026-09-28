<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use BackedEnum;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Idempotently register a permission, returning the model configured at
 * `permissions.models.permission`.
 *
 * Resolved through the config seam rather than a hard-coded class, so a host that swapped the
 * model gets *its* class back — and the row is created as that class, which is what fires the
 * model events the provider hangs the catalog cache invalidation on. `createOrFirst` makes it
 * race-safe under the unique `name` index.
 */
final readonly class FindOrCreatePermission
{
    public function execute(string|BackedEnum $name): Permission
    {
        return PermissionModel::query()->createOrFirst(['name' => PermissionRegistrar::nameOf($name)]);
    }
}
