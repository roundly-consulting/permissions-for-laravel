<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Idempotently register a role that belongs to one owner (a tenant, a team) —
 * `Permissions::owner($tenant)->role('reviewer')`. Two owners can each have their own
 * `reviewer`; neither clashes with the global one.
 *
 * Created as the configured `permissions.models.role` class; `createOrFirst` on the
 * `(owner_key, name)` unique index makes concurrent creation race-safe. Requires
 * `permissions.assignments.enabled`.
 */
final readonly class FindOrCreateOwnedRole
{
    public function execute(Model $owner, string|BackedEnum $name): Role
    {
        AssignmentRows::assertEnabled();
        $reference = ScopeReference::of($owner);

        return RoleModel::query()->createOrFirst(
            ['owner_key' => $reference->ownerKey(), 'name' => PermissionRegistrar::nameOf($name)],
            ['owner_type' => $reference->type, 'owner_id' => $reference->id],
        );
    }
}
