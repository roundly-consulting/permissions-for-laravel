<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Delete morph-pivot rows whose holder model no longer resolves.
 *
 * The `model_roles`/`model_permissions` pivots can't foreign-key the holder table, so a
 * deleted holder leaves grant rows behind. A record that later reuses the same primary key
 * would inherit them — this prunes them. A row whose `model_type` no longer resolves to a
 * model class at all is orphaned too.
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

        return $deleted;
    }

    private function pruneTable(ConnectionInterface $connection, string $table): int
    {
        $deleted = 0;

        foreach ($connection->table($table)->distinct()->pluck('model_type') as $type) {
            $type = (string) $type;
            $holder = $this->resolveHolder($type);

            if (! $holder instanceof Model) {
                // Unresolvable morph type — every row for it is orphaned.
                $deleted += $connection->table($table)->where('model_type', $type)->delete();

                continue;
            }

            $deleted += $connection->table($table)
                ->where('model_type', $type)
                ->whereNotIn('model_id', static function (Builder $query) use ($holder): void {
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
