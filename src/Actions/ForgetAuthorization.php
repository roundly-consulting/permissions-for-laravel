<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach every role and direct-permission grant a `HasRoles` holder has.
 *
 * Morph pivots have no foreign key to the holder table, so nothing cleans them up when a
 * holder is deleted. Leaving the rows behind lets a record that later reuses the same primary
 * key silently inherit the deleted holder's roles and permissions — run this from the holder's
 * `deleting` hook (or sweep with `Permissions::pruneOrphans()`).
 */
final readonly class ForgetAuthorization
{
    public function __construct(private PermissionRegistrar $registrar) {}

    public function execute(Model $holder): void
    {
        $roles = Grants::rolesOf($holder);
        $permissions = Grants::permissionsOf($holder);

        $holder->getConnection()->transaction(static function () use ($roles, $permissions): void {
            $roles->detach();
            $permissions->detach();
        });

        $holder->unsetRelation('roles');
        $holder->unsetRelation('permissions');
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
