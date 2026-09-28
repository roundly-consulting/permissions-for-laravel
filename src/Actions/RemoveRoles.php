<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach roles from a holder. The holder must use `HasRoles` and be saved; unknown role
 * names throw `RoleDoesNotExist`.
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

        $relation->detach(Grants::roles($roles)->modelKeys());

        $holder->unsetRelation('roles');
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
