<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Detach direct permissions from a holder (a `Role`, or any `HasRoles` model). Unknown
 * permission names throw `PermissionDoesNotExist`.
 */
final readonly class RevokePermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @param  iterable<mixed>  $permissions  names, backed enums, saved Permission models, nested iterables
     */
    public function execute(Model $holder, iterable $permissions): void
    {
        $relation = Grants::permissionsOf($holder);
        $ids = Grants::permissions($permissions)->modelKeys();

        $holder->getConnection()->transaction(static function () use ($relation, $ids): void {
            $relation->detach($ids);
        });

        $holder->unsetRelation('permissions');
        $this->registrar->forget();
    }
}
