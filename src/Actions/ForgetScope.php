<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Models\RoleAssignment;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Forget a scope that is going away — `Permissions::forgetScope($project)`, from the scope
 * model's `deleting` hook. Deletes every assignment made in it and every role it owns (their
 * permission links and assignments cascade). Without it, a record that later reuses the key
 * would inherit the grants. `Permissions::pruneOrphans()` sweeps scopes deleted without it.
 *
 * Returns the number of assignment rows plus owned roles deleted. Requires
 * `permissions.assignments.enabled`.
 */
final readonly class ForgetScope
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    public function execute(Model $scope): int
    {
        AssignmentRows::assertEnabled();
        $reference = ScopeReference::of($scope);
        $connection = RoleModel::new()->getConnection();

        $deleted = $connection->transaction(static function () use ($reference, $connection): int {
            $deleted = AssignmentRows::inScope(RoleAssignment::on($connection->getName()), $reference)->delete()
                + AssignmentRows::inScope(PermissionAssignment::on($connection->getName()), $reference)->delete();

            // One by one, as models: their `deleted` events invalidate the catalog cache, and
            // the database cascades their permission links and assignments.
            RoleModel::query()->where('owner_key', $reference->ownerKey())->get()
                ->each(static function (Role $role) use (&$deleted): void {
                    $role->delete();
                    $deleted++;
                });

            return $deleted;
        });

        $this->resolver->flush();
        $this->registrar->forgetAfterCommit($connection);

        return $deleted;
    }
}
