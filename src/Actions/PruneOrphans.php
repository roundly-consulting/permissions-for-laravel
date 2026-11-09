<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Delete morph-pivot rows whose holder model no longer resolves.
 *
 * The `model_roles`/`model_permissions` pivots can't foreign-key the holder table, so a
 * deleted holder leaves grant rows behind. A record that later reuses the same primary key
 * would inherit them — this prunes them. A row whose `model_type` no longer resolves to a
 * model class at all is orphaned too. With `permissions.assignments.enabled` on it also prunes
 * assignment rows whose holder or scope is gone, and roles whose owner is gone.
 */
final readonly class PruneOrphans
{
    /** @return int the number of pivot rows deleted */
    public function execute(): int
    {
        $connection = PermissionModel::new()->getConnection();
        $deleted = 0;

        foreach ([PermissionRegistrar::modelRolesTable(), PermissionRegistrar::modelPermissionsTable()] as $table) {
            $deleted += $this->pruneTable($connection, $table);
        }

        if (! PermissionRegistrar::assignmentsEnabled()) {
            return $deleted;
        }

        // 2.1: assignment rows of dead holders or dead scopes, and roles of dead owners (their
        // links and assignments cascade).
        foreach ([PermissionRegistrar::roleAssignmentsTable(), PermissionRegistrar::permissionAssignmentsTable()] as $table) {
            $deleted += $this->pruneTable($connection, $table);
            $deleted += $this->pruneTable($connection, $table, 'scope_type', 'scope_id');
        }

        $roles = $this->pruneTable($connection, PermissionRegistrar::rolesTable(), 'owner_type', 'owner_id');

        // Resolved here rather than injected, so the 2.0 constructor (none) stays as it was.
        if ($roles > 0) {
            app(PermissionRegistrar::class)->forgetAfterCommit($connection);
        }

        app(GrantResolver::class)->flush();

        return $deleted + $roles;
    }

    private function pruneTable(Connection $connection, string $table, string $typeColumn = 'model_type', string $idColumn = 'model_id'): int
    {
        $deleted = 0;

        $types = $connection->table($table);

        if ($typeColumn !== 'model_type') {
            $types->whereNotNull($typeColumn); // a null scope/owner is unscoped/global, never orphaned
        }

        foreach ($types->distinct()->pluck($typeColumn) as $type) {
            $type = (string) $type;
            $holder = $this->resolveHolder($type);

            if (! $holder instanceof Model) {
                // Unresolvable morph type — every row for it is orphaned.
                $deleted += $connection->table($table)->where($typeColumn, $type)->delete();

                continue;
            }

            $deleted += $connection->table($table)
                ->where($typeColumn, $type)
                ->whereNotIn($idColumn, static function (Builder $query) use ($holder): void {
                    $query->select($holder->getKeyName())->from($holder->getTable());
                })
                ->delete();
        }

        return $deleted;
    }

    private function resolveHolder(string $type): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return new $class;
    }
}
