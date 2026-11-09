<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Give a holder direct permissions inside a scope and/or between two moments —
 * `Permissions::for($user)->between($from, $until)->givePermissionTo('reports.export')`.
 *
 * Same rules as {@see GrantAssignedRoles}: additive writes upsert on the fingerprint (identical
 * concurrent writes leave one row, other windows add rows); an authoritative sync locks the
 * holder row, deletes the holder's other rows in this exact scope and upserts the targets, and
 * an unscoped (time-boxed) sync also detaches the holder's 2.0 direct permissions.
 *
 * The holder must use `HasRoles` (a `Role` has no assignments). Unknown permission names throw
 * `PermissionDoesNotExist` before anything is written. Requires `permissions.assignments.enabled`.
 */
final readonly class GrantAssignedPermissions
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    /**
     * @param  iterable<mixed>  $permissions  names, backed enums, saved Permission models, nested iterables
     */
    public function execute(Model $holder, iterable $permissions, GrantMode $mode, AssignmentContext $context): void
    {
        AssignmentRows::assertEnabled();
        Grants::rolesOf($holder);
        $legacy = Grants::permissionsOf($holder);
        AssignmentRows::assertWritable($context);

        $ids = array_values(Grants::permissions($permissions)->modelKeys());
        $rows = AssignmentRows::build($holder, 'permission_id', $ids, $context);
        $scope = $context->scope === null ? null : ScopeReference::of($context->scope);

        $holder->getConnection()->transaction(static function () use ($holder, $legacy, $rows, $mode, $scope): void {
            if ($mode->detaches()) {
                AssignmentRows::lockHolder($holder);

                AssignmentRows::inScope(AssignmentRows::permissions($holder), $scope)
                    ->whereNotIn('fingerprint', array_keys($rows))
                    ->delete();

                if ($scope === null) {
                    $legacy->detach();
                }
            }

            if ($rows !== []) {
                PermissionAssignment::on($holder->getConnectionName())
                    ->upsert(array_values($rows), uniqueBy: ['fingerprint'], update: ['updated_at']);
            }
        });

        $holder->unsetRelation('permissions');
        $this->resolver->forget($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
