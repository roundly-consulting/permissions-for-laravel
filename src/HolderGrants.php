<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\DataTransferObjects\Assignment;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\AssignmentList;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\EffectiveGrants;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\Scopes;

/**
 * Role and permission writes and checks scoped to one holder — `Permissions::for($holder)`.
 *
 * The same verbs as the `HasRoles` / `HasPermissions` traits (which delegate here), so there
 * is one vocabulary. Every write funnels through the {@see PermissionsManager}, so the fake
 * records it. Each write returns the holder.
 *
 * The scope is a boundary: role writes refuse a holder without `HasRoles` (a `Role` holds
 * permissions, never roles), permission writes refuse a holder without `HasPermissions`, and
 * both refuse an unsaved holder — each with a {@see PermissionException}, before anything is
 * written.
 *
 * **Scoped and time-boxed (2.1, `permissions.assignments.enabled`).** The immutable withers
 * `in()`, `from()`, `until()`, `between()` and `at()` return a new handle:
 *
 *     Permissions::for($user)->in($project)->until($friday)->assignRole('editor');
 *     Permissions::for($user)->in([$team, $parentTeam])->hasPermissionTo('posts.edit');
 *
 * A write takes exactly one scope; a check takes any number (any of them). A handle with no
 * scope and no window writes through the 2.0 path, unchanged. Removals (`removeRole`,
 * `revokePermissionTo`, `forgetAllAuthorization`) follow the scope only and clear every window.
 *
 * @template TModel of Model
 */
final readonly class HolderGrants
{
    /**
     * @param  TModel  $holder
     * @param  list<Model>|null  $scopes  null = unscoped
     */
    public function __construct(
        private PermissionsManager $manager,
        private Model $holder,
        private ?array $scopes = null,
        private ?CarbonImmutable $validFrom = null,
        private ?CarbonImmutable $validUntil = null,
        private ?CarbonImmutable $at = null,
    ) {}

    /**
     * Scope the handle to one scope model (writes), or any of several (checks); null goes
     * back to unscoped. Needs a `HasRoles` holder and assignments on.
     *
     * @param  Model|iterable<Model>|null  $scopes
     * @return self<TModel>
     */
    public function in(Model|iterable|null $scopes): self
    {
        $this->assertAssignable();

        if ($scopes === null) {
            return new self($this->manager, $this->holder, null, $this->validFrom, $this->validUntil, $this->at);
        }

        $models = Scopes::models($scopes);

        if ($models === []) {
            throw PermissionException::emptyScope();
        }

        return new self($this->manager, $this->holder, $models, $this->validFrom, $this->validUntil, $this->at);
    }

    /**
     * Writes start counting at this moment (inclusive).
     *
     * @return self<TModel>
     */
    public function from(?DateTimeInterface $from): self
    {
        return $this->between($from, $this->validUntil);
    }

    /**
     * Writes stop counting at this moment (exclusive).
     *
     * @return self<TModel>
     */
    public function until(?DateTimeInterface $until): self
    {
        return $this->between($this->validFrom, $until);
    }

    /**
     * Writes count from `$from` (inclusive) until `$until` (exclusive); null leaves that end open.
     *
     * @return self<TModel>
     */
    public function between(?DateTimeInterface $from, ?DateTimeInterface $until): self
    {
        $this->assertAssignable();

        $from = $from === null ? null : Scopes::utc($from);
        $until = $until === null ? null : Scopes::utc($until);
        Scopes::assertWindow($from, $until);

        return new self($this->manager, $this->holder, $this->scopes, $from, $until, $this->at);
    }

    /**
     * Answer checks as of this moment instead of now (reads only).
     *
     * @return self<TModel>
     */
    public function at(?DateTimeInterface $at): self
    {
        $this->assertAssignable();

        return new self($this->manager, $this->holder, $this->scopes, $this->validFrom, $this->validUntil, $at === null ? null : Scopes::utc($at));
    }

    /**
     * Additively assign roles — never detaches a role another caller assigned.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     * @return TModel
     */
    public function assignRole(string|BackedEnum|Role|iterable ...$roles): Model
    {
        if ($this->isPlain()) {
            $this->manager->grantRoles($this->holder, $roles, GrantMode::Additive);
        } else {
            $this->manager->grantAssignedRoles($this->holder, $roles, GrantMode::Additive, $this->writeContext());
        }

        return $this->holder;
    }

    /**
     * Remove roles — inside a scope, every window of them in that scope.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     * @return TModel
     */
    public function removeRole(string|BackedEnum|Role|iterable ...$roles): Model
    {
        if ($this->scopes === null) {
            $this->manager->removeRoles($this->holder, $roles);
        } else {
            $this->manager->removeAssignedRoles($this->holder, $roles, $this->writeScope());
        }

        return $this->holder;
    }

    /**
     * Make the given roles the exact, complete set (detaches the rest) — inside a scope, the
     * complete set of that scope only.
     *
     * @param  iterable<mixed>  $roles
     * @return TModel
     */
    public function syncRoles(iterable $roles): Model
    {
        if ($this->isPlain()) {
            $this->manager->grantRoles($this->holder, [$roles], GrantMode::Authoritative);
        } else {
            $this->manager->grantAssignedRoles($this->holder, [$roles], GrantMode::Authoritative, $this->writeContext());
        }

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
        if ($this->isPlain()) {
            $this->manager->grantPermissions($this->holder, $permissions, GrantMode::Additive);
        } else {
            $this->manager->grantAssignedPermissions($this->holder, $permissions, GrantMode::Additive, $this->writeContext());
        }

        return $this->holder;
    }

    /**
     * Revoke direct permissions — inside a scope, every window of them in that scope.
     *
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     * @return TModel
     */
    public function revokePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): Model
    {
        if ($this->scopes === null) {
            $this->manager->revokePermissions($this->holder, $permissions);
        } else {
            $this->manager->revokeAssignedPermissions($this->holder, $permissions, $this->writeScope());
        }

        return $this->holder;
    }

    /**
     * Make the given direct permissions the exact, complete set (detaches the rest) — inside a
     * scope, the complete set of that scope only.
     *
     * @param  iterable<mixed>  $permissions
     * @return TModel
     */
    public function syncPermissions(iterable $permissions): Model
    {
        if ($this->isPlain()) {
            $this->manager->grantPermissions($this->holder, [$permissions], GrantMode::Authoritative);
        } else {
            $this->manager->grantAssignedPermissions($this->holder, [$permissions], GrantMode::Authoritative, $this->writeContext());
        }

        return $this->holder;
    }

    /**
     * Detach every role and direct permission — call it from the holder's `deleting` hook. With
     * assignments on this also clears every scoped and time-boxed grant, in every scope;
     * inside `in($scope)` it clears that scope only.
     *
     * @return TModel
     */
    public function forgetAllAuthorization(): Model
    {
        if ($this->scopes === null) {
            $this->manager->forgetAuthorization($this->holder);
        } else {
            $this->manager->forgetAssignedAuthorization($this->holder, $this->writeScope());
        }

        return $this->holder;
    }

    /**
     * Whether the holder has any of these roles — in the handle's scopes (any of them) at the
     * check time.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  $roles
     */
    public function hasRole(string|BackedEnum|Role|iterable $roles): bool
    {
        $wanted = Grants::names($roles, Role::class, 'Role');

        return array_intersect($wanted, $this->effective()->roleNames()) !== [];
    }

    /**
     * Whether the holder has at least one of these roles.
     *
     * @param  string|BackedEnum|Role|iterable<mixed>  ...$roles
     */
    public function hasAnyRole(string|BackedEnum|Role|iterable ...$roles): bool
    {
        return $this->hasRole($roles);
    }

    /** Whether the holder has this permission — directly or through a role. */
    public function hasPermissionTo(string|BackedEnum|Permission $permission): bool
    {
        $name = Grants::names($permission, Permission::class, 'Permission')[0];

        return in_array($name, $this->effective()->permissionNames(), true);
    }

    /**
     * The role names the holder has here and now (2.0 roles included where they count).
     *
     * @return Collection<int, string>
     */
    public function roleNames(): Collection
    {
        return new Collection($this->effective()->roleNames());
    }

    /**
     * The permission names the holder has here and now — direct and through roles.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        return new Collection($this->effective()->permissionNames());
    }

    /**
     * Every role and direct permission the holder has, one entry per grant (each window its
     * own entry): unscoped handles list everything — the 2.0 grants and every scope; a scoped
     * handle lists only those scopes.
     *
     * @return Collection<int, Assignment>
     */
    public function assignments(): Collection
    {
        return new Collection((new AssignmentList($this->holder, $this->scopes, $this->checkTime()))->all());
    }

    /** No scope, no window: the 2.0 write path. */
    private function isPlain(): bool
    {
        return $this->scopes === null && $this->validFrom === null && $this->validUntil === null;
    }

    private function writeContext(): AssignmentContext
    {
        return new AssignmentContext(
            $this->scopes === null ? null : $this->writeScope(),
            $this->validFrom,
            $this->validUntil,
        );
    }

    private function writeScope(): Model
    {
        if ($this->scopes === null || count($this->scopes) !== 1) {
            throw PermissionException::oneScopeToWrite($this->scopes === null ? 0 : count($this->scopes));
        }

        return $this->scopes[0];
    }

    private function effective(): EffectiveGrants
    {
        return new EffectiveGrants(
            $this->holder,
            $this->scopes === null ? null : Scopes::references($this->scopes),
            $this->checkTime(),
        );
    }

    private function checkTime(): CarbonImmutable
    {
        return $this->at ?? Scopes::now();
    }

    private function assertAssignable(): void
    {
        AssignmentRows::assertEnabled();

        if (! in_array(HasRoles::class, class_uses_recursive($this->holder), true)) {
            throw PermissionException::cannotHold($this->holder, 'scoped or time-boxed grants', 'HasRoles');
        }
    }
}
