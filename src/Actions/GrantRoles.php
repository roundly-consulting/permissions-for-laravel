<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Give a holder roles — additively (`assignRole`: never detaches a role another caller
 * assigned) or authoritatively (`syncRoles`: the given set becomes the complete set).
 *
 * The holder must use `HasRoles` and be saved. Unknown role names throw `RoleDoesNotExist`
 * before anything is written.
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
            $holder->getConnection()->transaction(static function () use ($relation, $ids): void {
                $relation->sync($ids);
            });
        } else {
            $relation->syncWithoutDetaching($ids);
        }

        $holder->unsetRelation('roles');
        $this->registrar->forget();
    }
}
