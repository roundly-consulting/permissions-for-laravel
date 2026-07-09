<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Gives any Authenticatable model roles and direct permissions, and resolves
 * effective permissions (direct union via-roles).
 *
 * @phpstan-require-extends Model
 */
trait HasRoles
{
    use HasPermissions;

    /**
     * @return MorphToMany<Role, $this>
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(
            PermissionRegistrar::roleModel(),
            'model',
            PermissionRegistrar::tableName('model_roles', 'model_roles'),
            'model_id',
            'role_id',
        );
    }

    /**
     * @return MorphToMany<Permission, $this>
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(
            PermissionRegistrar::permissionModel(),
            'model',
            PermissionRegistrar::tableName('model_permissions', 'model_permissions'),
            'model_id',
            'permission_id',
        );
    }

    /**
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     */
    public function assignRole(string|BackedEnum|Role|iterable ...$roles): static
    {
        $this->roles()->syncWithoutDetaching($this->resolveRoles($roles)->modelKeys());
        $this->unsetRelation('roles');
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     */
    public function removeRole(string|BackedEnum|Role|iterable ...$roles): static
    {
        $this->roles()->detach($this->resolveRoles($roles)->modelKeys());
        $this->unsetRelation('roles');
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  iterable<mixed>  $roles
     */
    public function syncRoles(iterable $roles): static
    {
        $this->roles()->sync($this->resolveRoles([$roles])->modelKeys());
        $this->unsetRelation('roles');
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * @param  string|BackedEnum|iterable<mixed>  $role
     */
    public function hasRole(string|BackedEnum|iterable $role): bool
    {
        $names = $this->normalizeRoleNames($role);

        return $this->relatedRoles()->contains(
            static fn (Role $candidate): bool => in_array($candidate->name, $names, true),
        );
    }

    public function hasPermissionTo(string|BackedEnum|Permission $permission): bool
    {
        $name = $permission instanceof Permission
            ? $permission->name
            : PermissionRegistrar::nameOf($permission);

        return $this->getAllPermissions()->contains(
            static fn (Permission $candidate): bool => $candidate->name === $name,
        );
    }

    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(): Collection
    {
        return $this->relatedRoles()
            ->map(static fn (Role $role): string => $role->name)
            ->values();
    }

    /**
     * Direct permission grants only (excludes role-derived permissions).
     *
     * @return EloquentCollection<int, Permission>
     */
    public function getDirectPermissions(): EloquentCollection
    {
        return $this->relatedPermissions();
    }

    /**
     * Effective permissions: direct union via-roles, de-duped by id.
     *
     * @return EloquentCollection<int, Permission>
     */
    public function getAllPermissions(): EloquentCollection
    {
        $viaRoles = $this->relatedRoles()->flatMap(
            static fn (Role $role): EloquentCollection => $role->permissions,
        );

        return $this->relatedPermissions()
            ->merge($viaRoles)
            ->unique(static fn (Permission $permission): mixed => $permission->getKey())
            ->values();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  string|BackedEnum|iterable<mixed>  $role
     * @return Builder<Model>
     */
    public function scopeRole(Builder $query, string|BackedEnum|iterable $role): Builder
    {
        $names = $this->normalizeRoleNames($role);

        return $query->whereHas('roles', static function (Builder $roles) use ($names): void {
            $roles->whereIn('name', $names);
        });
    }

    /**
     * The loaded roles relation (eager-load aware, lazy otherwise).
     *
     * @return EloquentCollection<int, Role>
     */
    protected function relatedRoles(): EloquentCollection
    {
        /** @var EloquentCollection<int, Role> $roles */
        $roles = $this->getRelationValue('roles');

        return $roles;
    }

    /**
     * @param  string|BackedEnum|iterable<mixed>  $role
     * @return list<string>
     */
    protected function normalizeRoleNames(string|BackedEnum|iterable $role): array
    {
        $values = is_iterable($role) ? $role : [$role];
        $names = [];

        foreach ($this->flattenGrantArguments($values) as $item) {
            if ($item instanceof Role) {
                $names[] = $item->name;
            } elseif ($item instanceof BackedEnum) {
                $names[] = (string) $item->value;
            } elseif (is_string($item)) {
                $names[] = $item;
            } else {
                throw new PermissionException('Roles must be strings, backed enums, or Role models.');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Resolve names / enums / models / nested iterables to a de-duped collection.
     * Unknown names throw RoleDoesNotExist.
     *
     * @param  iterable<mixed>  $roles
     * @return EloquentCollection<int, Role>
     */
    protected function resolveRoles(iterable $roles): EloquentCollection
    {
        /** @var EloquentCollection<int, Role> $models */
        $models = new EloquentCollection;
        $names = [];

        foreach ($this->flattenGrantArguments($roles) as $role) {
            if ($role instanceof Role) {
                $models->push($role);
            } elseif ($role instanceof BackedEnum) {
                $names[] = (string) $role->value;
            } elseif (is_string($role)) {
                $names[] = $role;
            } else {
                throw new PermissionException('Roles must be strings, backed enums, or Role models.');
            }
        }

        $names = array_values(array_unique($names));

        if ($names !== []) {
            $model = PermissionRegistrar::roleModel();
            $found = $model::query()->whereIn('name', $names)->get();

            if ($found->count() < count($names)) {
                $missing = array_values(array_diff($names, $found->pluck('name')->all()));
                throw RoleDoesNotExist::named((string) $missing[0]);
            }

            $models = $models->merge($found);
        }

        return $models->unique(static fn (Role $role): mixed => $role->getKey())->values();
    }
}
