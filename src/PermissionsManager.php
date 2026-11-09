<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Actions\FindOrCreateOwnedRole;
use RoundlyConsulting\Permissions\Actions\FindOrCreatePermission;
use RoundlyConsulting\Permissions\Actions\FindOrCreateRole;
use RoundlyConsulting\Permissions\Actions\ForgetAssignedAuthorization;
use RoundlyConsulting\Permissions\Actions\ForgetAuthorization;
use RoundlyConsulting\Permissions\Actions\ForgetScope;
use RoundlyConsulting\Permissions\Actions\GrantAssignedPermissions;
use RoundlyConsulting\Permissions\Actions\GrantAssignedRoles;
use RoundlyConsulting\Permissions\Actions\GrantPermissions;
use RoundlyConsulting\Permissions\Actions\GrantRoles;
use RoundlyConsulting\Permissions\Actions\PruneExpired;
use RoundlyConsulting\Permissions\Actions\PruneOrphans;
use RoundlyConsulting\Permissions\Actions\RemoveAssignedRoles;
use RoundlyConsulting\Permissions\Actions\RemoveRoles;
use RoundlyConsulting\Permissions\Actions\RevokeAssignedPermissions;
use RoundlyConsulting\Permissions\Actions\RevokePermissions;
use RoundlyConsulting\Permissions\Actions\SyncFromEnum;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Support\ScopeResolvers;
use RoundlyConsulting\Permissions\Support\Scopes;
use RoundlyConsulting\Permissions\Testing\PermissionsFake;

/**
 * The permissions public API: the root behind the {@see Permissions} facade, and the class to
 * inject when you prefer dependency injection.
 *
 * - Catalog: `role()` / `permission()` register (find-or-create) a row as the configured model,
 *   `findRole()` / `findPermission()` look one up, `roles()` / `permissions()` list them,
 *   `exists()` asks the cached catalog, `syncFrom()` / `syncRolesFrom()` bootstrap rows from
 *   a backed enum.
 * - Grants: `for($holder)` scopes role and permission writes to one holder — the same verbs the
 *   `HasRoles` / `HasPermissions` traits offer, which delegate here. With
 *   `permissions.assignments.enabled` (2.1) the handle also takes a scope and a window:
 *   `for($user)->in($project)->until($friday)->assignRole('editor')`.
 * - Scopes (2.1): `owner($tenant)` for tenant-defined roles, `scope($project)` for scoped Gate
 *   checks, `resolveScopeUsing()` / `resolveRoleOwnerUsing()`, `forgetScope()`, `pruneExpired()`.
 * - Housekeeping: `cache()->forget()` / `->flushMemo()`, `pruneOrphans()`.
 *
 * It owns the {@see PermissionRegistrar} (the catalog cache and config resolvers) rather than
 * replacing it: the registrar stays the narrow, singleton cache, and this manager is the one
 * public entry point. Every write — from the facade, an injected manager, a `for()` handle, a
 * trait or a model method — funnels through one method here that resolves one action from the
 * container, so a host's container override applies everywhere and {@see PermissionsFake} sees
 * every call. Not final: the fake extends it.
 */
class PermissionsManager
{
    public function __construct(
        protected readonly Container $container,
        protected readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Scope role and permission writes to one holder: a `HasRoles` model (roles and direct
     * permissions) or a `Role` (permissions only).
     *
     * @template TModel of Model
     *
     * @param  TModel  $holder
     * @return HolderGrants<TModel>
     */
    public function for(Model $holder): HolderGrants
    {
        return new HolderGrants($this, $holder);
    }

    /** Find or create a role as the configured `permissions.models.role` class. */
    public function role(string|BackedEnum $name): Role
    {
        return $this->container->make(FindOrCreateRole::class)->execute($name);
    }

    /**
     * The role with this name, or null. A live query — never cached. With assignments on, the
     * global role only (`owner($tenant)->findRole()` finds an owned one).
     */
    public function findRole(string|BackedEnum $name): ?Role
    {
        $query = RoleModel::query()->where('name', PermissionRegistrar::nameOf($name));

        if (PermissionRegistrar::assignmentsEnabled()) {
            $query->where('owner_key', '');
        }

        return $query->first();
    }

    /**
     * Every role, ordered by name. A live query — never cached. With assignments on, the global
     * roles only (`owner($tenant)->roles()` lists an owner's).
     *
     * @return EloquentCollection<int, Role>
     */
    public function roles(): EloquentCollection
    {
        $query = RoleModel::query();

        if (PermissionRegistrar::assignmentsEnabled()) {
            $query->where('owner_key', '');
        }

        return $query->orderBy('name')->get();
    }

    /**
     * The roles one owner (a tenant, a team) defines for itself. Requires assignments on.
     */
    public function owner(Model $owner): OwnedRoles
    {
        AssignmentRows::assertEnabled();

        return new OwnedRoles($this, $owner);
    }

    /**
     * The Gate argument for a scoped check — `$user->can('posts.edit', Permissions::scope($p))`.
     * Several scopes mean "in any of them". Requires assignments on.
     *
     * @param  Model|iterable<Model>  $scopes
     */
    public function scope(Model|iterable $scopes): PermissionScope
    {
        AssignmentRows::assertEnabled();

        $models = Scopes::models($scopes);

        if ($models === []) {
            throw PermissionException::emptyScope();
        }

        return new PermissionScope($models);
    }

    /**
     * Give scope-less Gate checks an ambient scope — e.g. the current team:
     * `fn (Authorizable $user, string $ability): Model|iterable|null => …`. Returning null keeps
     * the check unscoped. Applies to the Gate only (`can()`, `@can`, `can:` middleware), never
     * to `hasPermissionTo()`. Pass null to remove it.
     */
    public function resolveScopeUsing(?Closure $resolver): void
    {
        $this->container->make(ScopeResolvers::class)->scopeUsing($resolver);
    }

    /**
     * Let a scope also use the roles of another owner — e.g. a project uses its tenant's roles:
     * `fn (Model $scope): ?Model => $scope->tenant`. Pass null to remove it.
     */
    public function resolveRoleOwnerUsing(?Closure $resolver): void
    {
        $this->container->make(ScopeResolvers::class)->roleOwnerUsing($resolver);
    }

    /**
     * Delete every assignment made in this scope and every role it owns — call it from the scope
     * model's `deleting` hook. Returns how many rows went. Requires assignments on.
     */
    public function forgetScope(Model $scope): int
    {
        return $this->container->make(ForgetScope::class)->execute($scope);
    }

    /**
     * Delete assignment rows whose window ended at or before `$endedBefore` (default now) —
     * table hygiene, never needed for correctness. Returns how many went. Requires assignments on.
     */
    public function pruneExpired(?DateTimeInterface $endedBefore = null): int
    {
        return $this->container->make(PruneExpired::class)->execute($endedBefore);
    }

    /** Find or create a permission as the configured `permissions.models.permission` class. */
    public function permission(string|BackedEnum $name): Permission
    {
        return $this->container->make(FindOrCreatePermission::class)->execute($name);
    }

    /** The permission with this name (the full row), or null. A live query — never cached. */
    public function findPermission(string|BackedEnum $name): ?Permission
    {
        return PermissionModel::query()->where('name', PermissionRegistrar::nameOf($name))->first();
    }

    /**
     * The permission catalog, from the cache: `id` + `name` only, relations resolve live. Use
     * `findPermission()` for a full row (with its `description`).
     *
     * @return EloquentCollection<int, Permission>
     */
    public function permissions(): EloquentCollection
    {
        return $this->registrar->permissions();
    }

    /** Whether a permission of this name is registered, answered from the cached catalog. */
    public function exists(string|BackedEnum $name): bool
    {
        return $this->registrar->exists($name);
    }

    /**
     * Register one permission per case of a backed enum (its `value` is the name). Additive:
     * rows the enum does not name are never touched.
     *
     * @param  string  $enum  a backed enum class
     */
    public function syncFrom(string $enum): SyncResult
    {
        return $this->container->make(SyncFromEnum::class)->execute($enum, $this->permissionModel());
    }

    /**
     * Register one role per case of a backed enum (its `value` is the name). Additive: rows
     * the enum does not name are never touched.
     *
     * @param  string  $enum  a backed enum class
     */
    public function syncRolesFrom(string $enum): SyncResult
    {
        return $this->container->make(SyncFromEnum::class)->execute($enum, $this->roleModel());
    }

    /**
     * Delete `model_roles` / `model_permissions` rows whose holder no longer exists; returns
     * how many were deleted. The `permissions:prune-orphans` command runs this.
     */
    public function pruneOrphans(): int
    {
        return $this->container->make(PruneOrphans::class)->execute();
    }

    /** The catalog cache: `forget()` it after bulk writes, `flushMemo()` in long-lived workers. */
    public function cache(): PermissionCache
    {
        return new PermissionCache($this);
    }

    /**
     * The configured role class (`permissions.models.role`) — e.g. `Permissions::roleModel()::query()`.
     *
     * @return class-string<Role>
     */
    public function roleModel(): string
    {
        return RoleModel::class();
    }

    /**
     * The configured permission class (`permissions.models.permission`).
     *
     * @return class-string<Permission>
     */
    public function permissionModel(): string
    {
        return PermissionModel::class();
    }

    /**
     * The funnel {@see HolderGrants::assignRole()} / `syncRoles()` and the `HasRoles` trait
     * call. Use `for($holder)->assignRole()` / `->syncRoles()` instead.
     *
     * @internal
     *
     * @param  iterable<mixed>  $roles
     */
    public function grantRoles(Model $holder, iterable $roles, GrantMode $mode): void
    {
        $this->container->make(GrantRoles::class)->execute($holder, $roles, $mode);
    }

    /**
     * The funnel behind `for($holder)->removeRole()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $roles
     */
    public function removeRoles(Model $holder, iterable $roles): void
    {
        $this->container->make(RemoveRoles::class)->execute($holder, $roles);
    }

    /**
     * The funnel behind `for($holder)->givePermissionTo()` / `->syncPermissions()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $permissions
     */
    public function grantPermissions(Model $holder, iterable $permissions, GrantMode $mode): void
    {
        $this->container->make(GrantPermissions::class)->execute($holder, $permissions, $mode);
    }

    /**
     * The funnel behind `for($holder)->revokePermissionTo()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $permissions
     */
    public function revokePermissions(Model $holder, iterable $permissions): void
    {
        $this->container->make(RevokePermissions::class)->execute($holder, $permissions);
    }

    /**
     * The funnel behind `for($holder)->forgetAllAuthorization()`.
     *
     * @internal
     */
    public function forgetAuthorization(Model $holder): void
    {
        $this->container->make(ForgetAuthorization::class)->execute($holder);
    }

    /**
     * The funnel behind a scoped / time-boxed `assignRole()` / `syncRoles()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $roles
     */
    public function grantAssignedRoles(Model $holder, iterable $roles, GrantMode $mode, AssignmentContext $context): void
    {
        $this->container->make(GrantAssignedRoles::class)->execute($holder, $roles, $mode, $context);
    }

    /**
     * The funnel behind `for($holder)->in($scope)->removeRole()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $roles
     */
    public function removeAssignedRoles(Model $holder, iterable $roles, Model $scope): void
    {
        $this->container->make(RemoveAssignedRoles::class)->execute($holder, $roles, $scope);
    }

    /**
     * The funnel behind a scoped / time-boxed `givePermissionTo()` / `syncPermissions()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $permissions
     */
    public function grantAssignedPermissions(Model $holder, iterable $permissions, GrantMode $mode, AssignmentContext $context): void
    {
        $this->container->make(GrantAssignedPermissions::class)->execute($holder, $permissions, $mode, $context);
    }

    /**
     * The funnel behind `for($holder)->in($scope)->revokePermissionTo()`.
     *
     * @internal
     *
     * @param  iterable<mixed>  $permissions
     */
    public function revokeAssignedPermissions(Model $holder, iterable $permissions, Model $scope): void
    {
        $this->container->make(RevokeAssignedPermissions::class)->execute($holder, $permissions, $scope);
    }

    /**
     * The funnel behind `for($holder)->in($scope)->forgetAllAuthorization()`.
     *
     * @internal
     */
    public function forgetAssignedAuthorization(Model $holder, Model $scope): void
    {
        $this->container->make(ForgetAssignedAuthorization::class)->execute($holder, $scope);
    }

    /**
     * The funnel behind `owner($owner)->role()`.
     *
     * @internal
     */
    public function ownedRole(Model $owner, string|BackedEnum $name): Role
    {
        return $this->container->make(FindOrCreateOwnedRole::class)->execute($owner, $name);
    }

    /**
     * The funnel behind `owner($owner)->syncRolesFrom()`.
     *
     * @internal
     */
    public function syncOwnedRolesFrom(Model $owner, string $enum): SyncResult
    {
        return $this->container->make(SyncFromEnum::class)->execute($enum, $this->roleModel(), $owner);
    }

    /**
     * The funnel behind `cache()->forget()`.
     *
     * @internal
     */
    public function forgetCache(): void
    {
        $this->registrar->forget();
    }

    /**
     * The funnel behind `cache()->flushMemo()`.
     *
     * @internal
     */
    public function flushMemo(): void
    {
        $this->registrar->flushMemo();
        $this->container->make(GrantResolver::class)->flush();
    }
}
