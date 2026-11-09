<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach direct permissions from a holder (a `Role`, or any `HasRoles` model). Unknown
 * permission names throw `PermissionDoesNotExist`. With `permissions.assignments.enabled` on it
 * also deletes the holder's unscoped assignment rows of those permissions (every window).
 */
final readonly class RevokePermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @param  iterable<mixed>  $permissions  names, backed enums, saved Permission models, nested iterables
     */
    public function execute(Model $holder, iterable $permissions): void
    {
        $relation = Grants::permissionsOf($holder);
        $ids = Grants::permissions($permissions)->modelKeys();

        $clearsAssignments = PermissionRegistrar::assignmentsEnabled() && AssignmentRows::holdsAssignments($holder);

        $holder->getConnection()->transaction(static function () use ($holder, $relation, $ids, $clearsAssignments): void {
            $relation->detach($ids);

            if ($clearsAssignments) {
                AssignmentRows::inScope(AssignmentRows::permissions($holder), null)->whereIn('permission_id', $ids)->delete();
            }
        });

        $holder->unsetRelation('permissions');
        AssignmentRows::forgetMemo($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
