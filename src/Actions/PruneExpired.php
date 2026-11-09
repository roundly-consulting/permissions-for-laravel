<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use DateTimeInterface;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Models\RoleAssignment;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Support\Scopes;

/**
 * Delete assignment rows whose window ended at or before `$endedBefore` (default now) —
 * `Permissions::pruneExpired()` / `permissions:prune-expired`.
 *
 * Table hygiene only: an ended window already grants nothing, so correctness never depends on
 * running it. Keep a grace period (`--after="30 days"`) if you read `assignments()` for history.
 * Returns the number of rows deleted. Requires `permissions.assignments.enabled`.
 */
final readonly class PruneExpired
{
    public function __construct(private GrantResolver $resolver) {}

    public function execute(?DateTimeInterface $endedBefore = null): int
    {
        AssignmentRows::assertEnabled();

        $cutoff = ($endedBefore === null ? Scopes::now() : Scopes::utc($endedBefore))->format(UtcDateTime::FORMAT);
        $connection = RoleModel::new()->getConnectionName();
        $deleted = 0;

        foreach ([RoleAssignment::class, PermissionAssignment::class] as $model) {
            $deleted += $model::on($connection)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<=', $cutoff)
                ->delete();
        }

        $this->resolver->flush();

        return $deleted;
    }
}
