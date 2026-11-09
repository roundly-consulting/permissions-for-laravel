<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopedRoles;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * Take roles away from a holder inside one scope — `Permissions::for($user)->in($project)
 * ->removeRole('editor')`. Every window of the role in that scope goes; other scopes and the
 * holder's unscoped grants are untouched. A name clears every role it can mean there (the
 * scope's own and the global one). Requires `permissions.assignments.enabled`.
 */
final readonly class RemoveAssignedRoles
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    /**
     * @param  iterable<mixed>  $roles  names, backed enums, saved Role models, nested iterables
     */
    public function execute(Model $holder, iterable $roles, Model $scope): void
    {
        AssignmentRows::assertEnabled();
        Grants::rolesOf($holder);

        $ids = ScopedRoles::resolveEveryMatch($roles, $scope)->modelKeys();

        AssignmentRows::inScope(AssignmentRows::roles($holder), ScopeReference::of($scope))
            ->whereIn('role_id', $ids)
            ->delete();

        $this->resolver->forget($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
