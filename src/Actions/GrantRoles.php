<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Give a holder roles — additively (`assignRole`: never detaches a role another caller
 * assigned) or authoritatively (`syncRoles`: the given set becomes the complete set).
 *
 * The holder must use `HasRoles` and be saved. Unknown role names throw `RoleDoesNotExist`
 * before anything is written. With `permissions.assignments.enabled` on, names resolve to
 * global roles only and a sync also clears the holder's unscoped assignment rows.
 */
final readonly class GrantRoles
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @param  iterable<mixed>  $roles  names, backed enums, saved Role models, nested iterables
     */
    public function execute(Model $holder, iterable $roles, GrantMode $mode): void
    {
        $relation = Grants::rolesOf($holder);
        $ids = Grants::roles($roles)->modelKeys();

        if ($mode->detaches()) {
            // Wrap detach+attach so a concurrent gate check never observes the empty
            // mid-sync role set.
            $holder->getConnection()->transaction(static function () use ($holder, $relation, $ids): void {
                $relation->sync($ids);

                // With assignments on, "the complete set" also covers the unscoped assignment
                // rows; scoped ones belong to their scope's own sync.
                if (PermissionRegistrar::assignmentsEnabled()) {
                    AssignmentRows::inScope(AssignmentRows::roles($holder), null)->delete();
                }
            });
        } else {
            $relation->syncWithoutDetaching($ids);
        }

        $holder->unsetRelation('roles');
        AssignmentRows::forgetMemo($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
