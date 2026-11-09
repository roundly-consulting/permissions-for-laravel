<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach every role and direct-permission grant a `HasRoles` holder has.
 *
 * Morph pivots have no foreign key to the holder table, so nothing cleans them up when a
 * holder is deleted. Leaving the rows behind lets a record that later reuses the same primary
 * key silently inherit the deleted holder's roles and permissions — run this from the holder's
 * `deleting` hook (or sweep with `Permissions::pruneOrphans()`). With
 * `permissions.assignments.enabled` on it also deletes every scoped and time-boxed assignment of
 * the holder, in every scope.
 */
final readonly class ForgetAuthorization
{
    public function __construct(private PermissionRegistrar $registrar) {}

    public function execute(Model $holder): void
    {
        $roles = Grants::rolesOf($holder);
        $permissions = Grants::permissionsOf($holder);

        $clearsAssignments = PermissionRegistrar::assignmentsEnabled();

        $holder->getConnection()->transaction(static function () use ($holder, $roles, $permissions, $clearsAssignments): void {
            $roles->detach();
            $permissions->detach();

            // The teardown verb: every assignment row of the holder goes, in every scope, so a
            // record that reuses the key inherits nothing. To leave one scope only, use
            // `Permissions::for($holder)->in($scope)->forgetAllAuthorization()`.
            if ($clearsAssignments) {
                AssignmentRows::roles($holder)->delete();
                AssignmentRows::permissions($holder)->delete();
            }
        });

        $holder->unsetRelation('roles');
        $holder->unsetRelation('permissions');
        AssignmentRows::forgetMemo($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
