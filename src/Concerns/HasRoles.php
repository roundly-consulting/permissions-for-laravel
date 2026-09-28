<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Gives any Authenticatable model roles and direct permissions, and resolves
 * effective permissions (direct union via-roles).
 *
 * Writes delegate to the {@see PermissionsManager} (`Permissions::for($this)->…`), so a
 * host's container override applies and `Permissions::fake()` records them; reads stay on
 * the model.
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
            PermissionRegistrar::modelRolesTable(),
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
            PermissionRegistrar::modelPermissionsTable(),
            'model_id',
            'permission_id',
        );
    }

    /**
     * Additively assign roles — never detaches a role another caller assigned.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     */
    public function assignRole(string|BackedEnum|Role|iterable ...$roles): static
    {
        app(PermissionsManager::class)->for($this)->assignRole(...$roles);

        return $this;
    }

    /**
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     */
    public function removeRole(string|BackedEnum|Role|iterable ...$roles): static
    {
        app(PermissionsManager::class)->for($this)->removeRole(...$roles);

        return $this;
    }

    /**
     * Make the given roles the exact, complete set (detaches the rest).
     *
     * @param  iterable<mixed>  $roles
     */
    public function syncRoles(iterable $roles): static
    {
        app(PermissionsManager::class)->for($this)->syncRoles($roles);

        return $this;
    }

    /**
     * Detach every role and direct-permission grant this model holds.
     *
     * Call this from the holder model's `deleting` event (morph pivots have no
     * foreign key to the holder table, so nothing cleans them up automatically).
     * Leaving pivot rows behind lets a record that later reuses the same primary
     * key silently inherit the deleted holder's roles and permissions.
     */
    public function forgetAllAuthorization(): static
    {
        app(PermissionsManager::class)->for($this)->forgetAllAuthorization();

        return $this;
    }

    /**
     * @param  string|BackedEnum|iterable<mixed>  $role
     */
    public function hasRole(string|BackedEnum|iterable $role): bool
    {
        $names = Grants::names($role, Role::class, 'Role');

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
        $names = Grants::names($role, Role::class, 'Role');

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
}
