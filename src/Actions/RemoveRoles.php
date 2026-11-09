<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach roles from a holder. The holder must use `HasRoles` and be saved; unknown role
 * names throw `RoleDoesNotExist`. With `permissions.assignments.enabled` on it also deletes the
 * holder's unscoped assignment rows of those roles (every window).
 */
final readonly class RemoveRoles
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @param  iterable<mixed>  $roles  names, backed enums, saved Role models, nested iterables
     */
    public function execute(Model $holder, iterable $roles): void
    {
        $relation = Grants::rolesOf($holder);

        $ids = Grants::roles($roles)->modelKeys();

        if (PermissionRegistrar::assignmentsEnabled()) {
            // Every unscoped window of these roles goes too; scoped ones stay with their scope.
            $holder->getConnection()->transaction(static function () use ($holder, $relation, $ids): void {
                $relation->detach($ids);
                AssignmentRows::inScope(AssignmentRows::roles($holder), null)->whereIn('role_id', $ids)->delete();
            });
        } else {
            $relation->detach($ids);
        }

        $holder->unsetRelation('roles');
        AssignmentRows::forgetMemo($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
