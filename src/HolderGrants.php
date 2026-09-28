<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * Role and permission writes scoped to one holder — `Permissions::for($holder)`.
 *
 * The same verbs as the `HasRoles` / `HasPermissions` traits (which delegate here), so there
 * is one vocabulary. Every write funnels through the {@see PermissionsManager}, so the fake
 * records it. Each returns the holder.
 *
 * The scope is a boundary: role writes refuse a holder without `HasRoles` (a `Role` holds
 * permissions, never roles), permission writes refuse a holder without `HasPermissions`, and
 * both refuse an unsaved holder — each with a {@see PermissionException}, before anything is
 * written.
 *
 * @template TModel of Model
 */
final readonly class HolderGrants
{
    /**
     * @param  TModel  $holder
     */
    public function __construct(
        private PermissionsManager $manager,
        private Model $holder,
    ) {}

    /**
     * Additively assign roles — never detaches a role another caller assigned.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     * @return TModel
     */
    public function assignRole(string|BackedEnum|Role|iterable ...$roles): Model
    {
        $this->manager->grantRoles($this->holder, $roles, GrantMode::Additive);

        return $this->holder;
    }

    /**
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     * @return TModel
     */
    public function removeRole(string|BackedEnum|Role|iterable ...$roles): Model
    {
        $this->manager->removeRoles($this->holder, $roles);

        return $this->holder;
    }

    /**
     * Make the given roles the exact, complete set (detaches the rest).
     *
     * @param  iterable<mixed>  $roles
     * @return TModel
     */
    public function syncRoles(iterable $roles): Model
    {
        $this->manager->grantRoles($this->holder, [$roles], GrantMode::Authoritative);

        return $this->holder;
    }

    /**
     * Additively grant direct permissions — never detaches a grant another caller made.
     *
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     * @return TModel
     */
    public function givePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): Model
    {
        $this->manager->grantPermissions($this->holder, $permissions, GrantMode::Additive);

        return $this->holder;
    }

    /**
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     * @return TModel
     */
    public function revokePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): Model
    {
        $this->manager->revokePermissions($this->holder, $permissions);

        return $this->holder;
    }

    /**
     * Make the given direct permissions the exact, complete set (detaches the rest).
     *
     * @param  iterable<mixed>  $permissions
     * @return TModel
     */
    public function syncPermissions(iterable $permissions): Model
    {
        $this->manager->grantPermissions($this->holder, [$permissions], GrantMode::Authoritative);

        return $this->holder;
    }

    /**
     * Detach every role and direct permission — call it from the holder's `deleting` hook.
     *
     * @return TModel
     */
    public function forgetAllAuthorization(): Model
    {
        $this->manager->forgetAuthorization($this->holder);

        return $this->holder;
    }
}
