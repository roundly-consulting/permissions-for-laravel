<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Give a holder (a `Role`, or any `HasRoles` model) direct permissions — additively
 * (`givePermissionTo`: never detaches a grant another caller registered, the invariant the
 * whole registry is sold on) or authoritatively (`syncPermissions`: the given set becomes the
 * complete set).
 *
 * Unknown permission names throw `PermissionDoesNotExist` before anything is written.
 */
final readonly class GrantPermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @param  iterable<mixed>  $permissions  names, backed enums, saved Permission models, nested iterables
     */
    public function execute(Model $holder, iterable $permissions, GrantMode $mode): void
    {
        $relation = Grants::permissionsOf($holder);
        $ids = Grants::permissions($permissions)->modelKeys();

        // Wrap detach+attach so a concurrent gate check never observes the empty mid-sync
        // grant set, and interleaved authoritative syncs can't persist a union/loss neither
        // caller asked for.
        $holder->getConnection()->transaction(static function () use ($relation, $ids, $mode): void {
            if ($mode->detaches()) {
                $relation->sync($ids);
            } else {
                $relation->syncWithoutDetaching($ids);
            }
        });

        $holder->unsetRelation('permissions');
        $this->registrar->forgetAfterCommit($holder->getConnection());
    }
}
