<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * The role and permission names a holder has, for one check: in which scopes (null = an
 * unscoped check), at which moment, and whether the 2.0 grants are counted.
 *
 * - Unscoped check: 2.0 grants + unscoped assignment rows.
 * - Scoped check: rows in those scopes, plus — when `global_applies_in_scope` is on — the
 *   2.0 grants and the unscoped rows. A scoped row never satisfies an unscoped check.
 *
 * Assignment rows are read only while assignments are on.
 *
 * @internal
 */
final readonly class EffectiveGrants
{
    /**
     * @param  list<ScopeReference>|null  $scopes
     */
    public function __construct(
        private Model $holder,
        private ?array $scopes,
        private CarbonImmutable $at,
        private bool $includeTwoPointZero = true,
    ) {}

    /** @return list<string> */
    public function roleNames(): array
    {
        if (! self::uses($this->holder, HasRoles::class)) {
            throw PermissionException::cannotHold($this->holder, 'roles', 'HasRoles');
        }

        $names = [];

        if ($this->countsUnscoped() && $this->includeTwoPointZero) {
            foreach ($this->legacyRoles() as $role) {
                $names[] = $role->name;
            }
        }

        foreach ($this->activeRows(AssignmentKind::Role) as $row) {
            $names[] = $row->name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Direct + via roles.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $names = [];

        if ($this->countsUnscoped() && $this->includeTwoPointZero) {
            foreach ($this->legacyPermissions() as $permission) {
                $names[] = $permission->name;
            }
        }

        foreach ($this->activeRows(null) as $row) {
            if ($row->kind === AssignmentKind::Permission) {
                $names[] = $row->name;

                continue;
            }

            foreach ($row->permissions as $permission) {
                $names[] = $permission;
            }
        }

        return array_values(array_unique($names));
    }

    private function countsUnscoped(): bool
    {
        return $this->scopes === null || PermissionRegistrar::globalAppliesInScope();
    }

    /** @return list<GrantRow> */
    private function activeRows(?AssignmentKind $kind): array
    {
        if (! PermissionRegistrar::assignmentsEnabled() || ! self::uses($this->holder, HasRoles::class)) {
            return [];
        }

        $global = PermissionRegistrar::globalAppliesInScope();

        return array_values(array_filter(
            app(GrantResolver::class)->rows($this->holder),
            fn (GrantRow $row): bool => ($kind === null || $row->kind === $kind)
                && $row->activeAt($this->at)
                && $row->appliesIn($this->scopes, $global),
        ));
    }

    /** @return list<Role> */
    private function legacyRoles(): array
    {
        $roles = [];

        foreach ($this->related('roles') as $role) {
            if ($role instanceof Role) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** @return list<Permission> */
    private function legacyPermissions(): array
    {
        if (! self::uses($this->holder, HasPermissions::class)) {
            return [];
        }

        $permissions = [];

        foreach ($this->related('permissions') as $permission) {
            if ($permission instanceof Permission) {
                $permissions[] = $permission;
            }
        }

        if (self::uses($this->holder, HasRoles::class)) {
            foreach ($this->legacyRoles() as $role) {
                foreach ($role->permissions as $permission) {
                    $permissions[] = $permission;
                }
            }
        }

        return $permissions;
    }

    /** @return iterable<mixed> */
    private function related(string $relation): iterable
    {
        $value = $this->holder->getRelationValue($relation);

        return is_iterable($value) ? $value : [];
    }

    private static function uses(Model $holder, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($holder), true);
    }
}
