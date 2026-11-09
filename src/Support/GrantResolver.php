<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Models\RoleAssignment;

/**
 * The in-process memo of each holder's scoped / time-boxed assignment rows.
 *
 * It stores **rows with their windows**, never a yes/no, so every check filters against the
 * clock at check time: a long-lived instance, a queue worker or an Octane worker can never
 * serve a grant whose window has ended. Nothing is shared between processes, so there is
 * nothing to invalidate after a commit.
 *
 * Flushed per holder by every write funnel, wholesale at every Octane request/task/tick and
 * queued job, by `Permissions::cache()->flushMemo()`, and whenever a transaction rolls back
 * (a row read inside it may never have committed).
 *
 * Only read while `permissions.assignments.enabled` is on.
 *
 * @internal
 */
final class GrantResolver
{
    /** @var array<string, list<GrantRow>> */
    private array $memo = [];

    /**
     * Every assignment row of the holder — any scope, any window (expired ones included, so an
     * `at()` check in the past still answers correctly).
     *
     * @return list<GrantRow>
     */
    public function rows(Model $holder): array
    {
        $key = self::keyOf($holder);

        if ($key === null) {
            return [];
        }

        return $this->memo[$key] ??= $this->load($holder);
    }

    public function forget(Model $holder): void
    {
        $key = self::keyOf($holder);

        if ($key !== null) {
            unset($this->memo[$key]);
        }
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return list<GrantRow> */
    private function load(Model $holder): array
    {
        $roleRows = $this->roleRows($holder);
        $permissionRows = $this->permissionRows($holder);

        return [...$roleRows, ...$permissionRows];
    }

    /** @return list<GrantRow> */
    private function roleRows(Model $holder): array
    {
        $assignments = RoleAssignment::on($holder->getConnectionName())
            ->with('role.permissions')
            ->where('model_type', $holder->getMorphClass())
            ->where('model_id', $holder->getKey())
            ->get();

        $rows = [];

        foreach ($assignments as $assignment) {
            $role = $assignment->role;

            if ($role === null) {
                continue;
            }

            $rows[] = new GrantRow(
                AssignmentKind::Role,
                $role->name,
                self::scopeOf($assignment->scope_type, $assignment->scope_id),
                $assignment->valid_from,
                $assignment->valid_until,
                array_values(array_map(
                    static fn (Permission $permission): string => $permission->name,
                    $role->permissions->all(),
                )),
            );
        }

        return $rows;
    }

    /** @return list<GrantRow> */
    private function permissionRows(Model $holder): array
    {
        $assignments = PermissionAssignment::on($holder->getConnectionName())
            ->with('permission')
            ->where('model_type', $holder->getMorphClass())
            ->where('model_id', $holder->getKey())
            ->get();

        $rows = [];

        foreach ($assignments as $assignment) {
            $permission = $assignment->permission;

            if ($permission === null) {
                continue;
            }

            $rows[] = new GrantRow(
                AssignmentKind::Permission,
                $permission->name,
                self::scopeOf($assignment->scope_type, $assignment->scope_id),
                $assignment->valid_from,
                $assignment->valid_until,
            );
        }

        return $rows;
    }

    private static function scopeOf(?string $type, int|string|null $id): ?ScopeReference
    {
        return $type === null || $id === null ? null : new ScopeReference($type, (string) $id);
    }

    private static function keyOf(Model $holder): ?string
    {
        $key = $holder->getKey();

        return $key === null || ! is_scalar($key) ? null : $holder->getMorphClass()."\x1F".$key;
    }
}
