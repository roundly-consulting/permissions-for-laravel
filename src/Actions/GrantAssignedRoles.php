<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Models\RoleAssignment;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopedRoles;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Give a holder roles inside a scope and/or between two moments —
 * `Permissions::for($user)->in($project)->until($friday)->assignRole('editor')`.
 *
 * - **Additive** (`assignRole`): one row per role, upserted on its fingerprint, so a repeated or
 *   concurrent identical assignment leaves one row. The same role with another window is a
 *   separate row; the holder has the role whenever any of its windows is open.
 * - **Authoritative** (`syncRoles`): inside one transaction that holds a lock on the holder row,
 *   every row of the holder in this exact scope that is not one of the targets is deleted, then
 *   the targets are upserted — concurrent syncs serialize, and exactly one caller's set wins.
 *   An unscoped (time-boxed) sync also detaches the holder's 2.0 roles, so the given set really
 *   is the complete unscoped set.
 *
 * Names resolve to the scope's own role first, then to global roles; an owned role outside its
 * owner's scope throws. Requires `permissions.assignments.enabled`.
 */
final readonly class GrantAssignedRoles
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    /**
     * @param  iterable<mixed>  $roles  names, backed enums, saved Role models, nested iterables
     */
    public function execute(Model $holder, iterable $roles, GrantMode $mode, AssignmentContext $context): void
    {
        AssignmentRows::assertEnabled();
        $legacy = Grants::rolesOf($holder);
        AssignmentRows::assertWritable($context);

        $ids = array_values(ScopedRoles::resolve($roles, $context->scope)->modelKeys());
        $rows = AssignmentRows::build($holder, 'role_id', $ids, $context);
        $scope = $context->scope === null ? null : ScopeReference::of($context->scope);

        $holder->getConnection()->transaction(static function () use ($holder, $legacy, $rows, $mode, $scope): void {
            if ($mode->detaches()) {
                AssignmentRows::lockHolder($holder);

                AssignmentRows::inScope(AssignmentRows::roles($holder), $scope)
                    ->whereNotIn('fingerprint', array_keys($rows))
                    ->delete();

                if ($scope === null) {
                    $legacy->detach();
                }
            }

            if ($rows !== []) {
                RoleAssignment::on($holder->getConnectionName())
                    ->upsert(array_values($rows), uniqueBy: ['fingerprint'], update: ['updated_at']);
            }
        });

        $holder->unsetRelation('roles');
        $this->resolver->forget($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
