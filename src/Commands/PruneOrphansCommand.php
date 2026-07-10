<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Delete morph-pivot rows whose holder model no longer resolves.
 *
 * The `model_roles`/`model_permissions` pivots can't foreign-key the holder
 * table, so a deleted holder leaves grant rows behind. A record that later
 * reuses the same primary key would inherit them — this command prunes them.
 */
final class PruneOrphansCommand extends Command
{
    /** @var string */
    protected $signature = 'permissions:prune-orphans';

    /** @var string */
    protected $description = 'Delete role/permission pivot rows whose holder model no longer exists.';

    public function handle(): int
    {
        $model = PermissionRegistrar::permissionModel();
        $connection = (new $model)->getConnection();

        $deleted = 0;

        foreach ([
            PermissionRegistrar::tableName('model_roles', 'model_roles'),
            PermissionRegistrar::tableName('model_permissions', 'model_permissions'),
        ] as $table) {
            $deleted += $this->pruneTable($connection, $table);
        }

        $this->info("Pruned {$deleted} orphaned authorization row(s).");

        return self::SUCCESS;
    }

    private function pruneTable(ConnectionInterface $connection, string $table): int
    {
        $deleted = 0;

        foreach ($connection->table($table)->distinct()->pluck('model_type') as $type) {
            $type = (string) $type;
            $holder = $this->resolveHolder($type);

            if ($holder === null) {
                // Unresolvable morph type — every row for it is orphaned.
                $deleted += $connection->table($table)->where('model_type', $type)->delete();

                continue;
            }

            $deleted += $connection->table($table)
                ->where('model_type', $type)
                ->whereNotIn('model_id', function (Builder $query) use ($holder): void {
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
