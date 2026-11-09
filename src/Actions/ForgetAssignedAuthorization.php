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
 * Drop every role and direct permission a holder has inside one scope —
 * `Permissions::for($member)->in($team)->forgetAllAuthorization()`, e.g. when the member leaves
 * the team. Other scopes and the unscoped grants stay. Requires
 * `permissions.assignments.enabled`.
 */
final readonly class ForgetAssignedAuthorization
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private GrantResolver $resolver,
    ) {}

    public function execute(Model $holder, Model $scope): void
    {
        AssignmentRows::assertEnabled();
        Grants::rolesOf($holder);
        $reference = ScopeReference::of($scope);

        $holder->getConnection()->transaction(static function () use ($holder, $reference): void {
            AssignmentRows::inScope(AssignmentRows::roles($holder), $reference)->delete();
            AssignmentRows::inScope(AssignmentRows::permissions($holder), $reference)->delete();
        });

        $this->resolver->forget($holder);
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
