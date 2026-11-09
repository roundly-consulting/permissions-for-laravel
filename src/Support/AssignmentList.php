<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\DataTransferObjects\Assignment;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Models\RoleAssignment;

/**
 * The holder's grants as {@see Assignment} entries — `Permissions::for($holder)->assignments()`.
 * A live query (not the grant memo), with each row's scope model eager-loaded.
 *
 * @internal
 */
final readonly class AssignmentList
{
    /**
     * @param  list<Model>|null  $scopes  null = everything; otherwise those scopes only
     */
    public function __construct(
        private Model $holder,
        private ?array $scopes,
        private CarbonImmutable $at,
    ) {}

    /** @return list<Assignment> */
    public function all(): array
    {
        $entries = $this->scopes === null ? $this->twoPointZero() : [];

        if (! PermissionRegistrar::assignmentsEnabled() || ! self::uses($this->holder, HasRoles::class)) {
            return $entries;
        }

        foreach ($this->narrow(AssignmentRows::roles($this->holder))->with(['role', 'scope'])->get() as $row) {
            if ($row->role instanceof Role) {
                $entries[] = $this->entry(AssignmentKind::Role, $row->role->name, $row);
            }
        }

        foreach ($this->narrow(AssignmentRows::permissions($this->holder))->with(['permission', 'scope'])->get() as $row) {
            if ($row->permission instanceof Permission) {
                $entries[] = $this->entry(AssignmentKind::Permission, $row->permission->name, $row);
            }
        }

        return $entries;
    }

    /** @return list<Assignment> */
    private function twoPointZero(): array
    {
        $entries = [];

        if (self::uses($this->holder, HasRoles::class)) {
            foreach (self::related($this->holder, 'roles') as $role) {
                if ($role instanceof Role) {
                    $entries[] = new Assignment(AssignmentKind::Role, $role->name, null, null, null, true);
                }
            }
        }

        if (self::uses($this->holder, HasPermissions::class)) {
            foreach (self::related($this->holder, 'permissions') as $permission) {
                if ($permission instanceof Permission) {
                    $entries[] = new Assignment(AssignmentKind::Permission, $permission->name, null, null, null, true);
                }
            }
        }

        return $entries;
    }

    /**
     * @template TModel of RoleAssignment|PermissionAssignment
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function narrow(Builder $query): Builder
    {
        if ($this->scopes === null) {
            return $query;
        }

        $references = Scopes::references($this->scopes);

        return $query->where(static function (Builder $query) use ($references): void {
            foreach ($references as $reference) {
                $query->orWhere(static function (Builder $query) use ($reference): void {
                    $query->where('scope_type', $reference->type)->where('scope_id', $reference->id);
                });
            }
        });
    }

    private function entry(AssignmentKind $kind, string $name, RoleAssignment|PermissionAssignment $row): Assignment
    {
        $scope = $row->scope_type === null ? null : $row->getRelationValue('scope');
        $grant = new GrantRow($kind, $name, null, $row->valid_from, $row->valid_until);

        return new Assignment(
            $kind,
            $name,
            $scope instanceof Model ? $scope : null,
            $row->valid_from,
            $row->valid_until,
            $grant->activeAt($this->at),
        );
    }

    /** @return iterable<mixed> */
    private static function related(Model $holder, string $relation): iterable
    {
        $value = $holder->getRelationValue($relation);

        return is_iterable($value) ? $value : [];
    }

    private static function uses(Model $holder, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($holder), true);
    }
}
