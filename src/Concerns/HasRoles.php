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
use RoundlyConsulting\Permissions\Support\EffectiveGrants;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleInScope;
use RoundlyConsulting\Permissions\Support\Scopes;

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
     * Whether the model has any of these roles (unscoped). With assignments on, an unscoped
     * time-boxed role counts while its window is open; a scoped one never does here — use
     * `hasRoleIn()`.
     *
     * @param  string|BackedEnum|iterable<mixed>  $role
     */
    public function hasRole(string|BackedEnum|iterable $role): bool
    {
        $names = Grants::names($role, Role::class, 'Role');

        if ($this->relatedRoles()->contains(
            static fn (Role $candidate): bool => in_array($candidate->name, $names, true),
        )) {
            return true;
        }

        return PermissionRegistrar::assignmentsEnabled()
            && array_intersect($names, $this->assignedOnly()->roleNames()) !== [];
    }

    /**
     * Whether the model has this permission, directly or through a role (unscoped). With
     * assignments on, unscoped time-boxed grants count while their window is open; scoped ones
     * never do here — use `hasPermissionIn()`.
     */
    public function hasPermissionTo(string|BackedEnum|Permission $permission): bool
    {
        $name = $permission instanceof Permission
            ? $permission->name
            : PermissionRegistrar::nameOf($permission);

        if ($this->getAllPermissions()->contains(
            static fn (Permission $candidate): bool => $candidate->name === $name,
        )) {
            return true;
        }

        return PermissionRegistrar::assignmentsEnabled()
            && in_array($name, $this->assignedOnly()->permissionNames(), true);
    }

    /**
     * Whether the model has any of these roles inside the scope (or any of the scopes) — sugar
     * for `Permissions::for($this)->in($scope)->hasRole($role)`. Requires assignments on.
     *
     * @param  Model|iterable<Model>  $scope
     * @param  string|BackedEnum|iterable<mixed>  $role
     */
    public function hasRoleIn(Model|iterable $scope, string|BackedEnum|iterable $role): bool
    {
        return app(PermissionsManager::class)->for($this)->in($scope)->hasRole($role);
    }

    /**
     * Whether the model has this permission inside the scope (or any of the scopes) — sugar for
     * `Permissions::for($this)->in($scope)->hasPermissionTo($permission)`. Requires assignments on.
     *
     * @param  Model|iterable<Model>  $scope
     */
    public function hasPermissionIn(Model|iterable $scope, string|BackedEnum|Permission $permission): bool
    {
        return app(PermissionsManager::class)->for($this)->in($scope)->hasPermissionTo($permission);
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

        $global = PermissionRegistrar::assignmentsEnabled();

        return $query->whereHas('roles', static function (Builder $roles) use ($names, $global): void {
            $roles->whereIn('name', $names);

            if ($global) {
                $roles->where('owner_key', '');
            }
        });
    }

    /**
     * Models that have any of these roles inside the scope (or any of the scopes), now:
     * `User::roleIn($project, 'editor')`. Counts the 2.0 roles and the unscoped rows too while
     * `global_applies_in_scope` is on. Requires assignments on.
     *
     * @param  Builder<Model>  $query
     * @param  Model|iterable<Model>  $scope
     * @param  string|BackedEnum|iterable<mixed>  $role
     * @return Builder<Model>
     */
    public function scopeRoleIn(Builder $query, Model|iterable $scope, string|BackedEnum|iterable $role): Builder
    {
        return (new RoleInScope($scope, Grants::names($role, Role::class, 'Role')))->apply($query, $this);
    }

    /**
     * The unscoped assignment rows only (the 2.0 grants are checked by the caller).
     */
    private function assignedOnly(): EffectiveGrants
    {
        return new EffectiveGrants($this, null, Scopes::now(), includeTwoPointZero: false);
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
