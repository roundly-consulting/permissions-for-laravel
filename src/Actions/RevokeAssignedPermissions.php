<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Take direct permissions away from a holder inside one scope — every window of them in that
 * scope. Other scopes and the holder's unscoped grants are untouched. Requires
 * `permissions.assignments.enabled`.
 */
final readonly class RevokeAssignedPermissions
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    /**
     * @param  iterable<mixed>  $permissions  names, backed enums, saved Permission models, nested iterables
     */
    public function execute(Model $holder, iterable $permissions, Model $scope): void
    {
        AssignmentRows::assertEnabled();
        Grants::rolesOf($holder);

        $ids = Grants::permissions($permissions)->modelKeys();

        AssignmentRows::inScope(AssignmentRows::permissions($holder), ScopeReference::of($scope))
            ->whereIn('permission_id', $ids)
            ->delete();

        $this->resolver->forget($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
