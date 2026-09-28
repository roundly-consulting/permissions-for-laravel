<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Actions\FindOrCreatePermission;
use RoundlyConsulting\Permissions\Actions\FindOrCreateRole;
use RoundlyConsulting\Permissions\Actions\ForgetAuthorization;
use RoundlyConsulting\Permissions\Actions\GrantPermissions;
use RoundlyConsulting\Permissions\Actions\GrantRoles;
use RoundlyConsulting\Permissions\Actions\PruneOrphans;
use RoundlyConsulting\Permissions\Actions\RemoveRoles;
use RoundlyConsulting\Permissions\Actions\RevokePermissions;
use RoundlyConsulting\Permissions\Actions\SyncFromEnum;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;
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
 *   `HasRoles` / `HasPermissions` traits offer, which delegate here.
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

    /** The role with this name, or null. A live query — never cached. */
    public function findRole(string|BackedEnum $name): ?Role
    {
        return RoleModel::query()->where('name', PermissionRegistrar::nameOf($name))->first();
    }

    /**
     * Every role, ordered by name. A live query — never cached.
     *
     * @return EloquentCollection<int, Role>
     */
    public function roles(): EloquentCollection
    {
        return RoleModel::query()->orderBy('name')->get();
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
    }
}
