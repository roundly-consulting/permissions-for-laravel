<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Testing;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\Scopes;

/**
 * The recording, still-performing stand-in {@see Permissions::fake()} swaps in.
 *
 * Every operation runs against the database as usual — grants land, the Gate answers, reads
 * see the rows — while each write is recorded, from wherever it came: the facade, an injected
 * {@see PermissionsManager}, a `for($holder)` handle, the `HasRoles` / `HasPermissions` traits,
 * `Role::findOrCreate()` / `Permission::findOrCreate()`, or the artisan commands.
 *
 * A write is recorded only once it succeeded, with its arguments normalized to names — so
 * `assertRoleAssigned($user, RoleName::Admin)` matches `$user->assignRole('admin')`.
 *
 * The package's own cache housekeeping is not recorded: the invalidation every grant and
 * every role/permission save triggers, and the memo reset at each queued job or Octane
 * request. `assertCacheForgotten()` / `assertMemoFlushed()` see only explicit
 * `Permissions::cache()` calls (and `permissions:cache-reset`).
 *
 * This class lives in src/ so host apps can use it; it depends on PHPUnit's Assert, which is
 * always present in a Laravel app's dev dependencies.
 *
 * Scoped and time-boxed writes (2.1) are recorded under the same kinds with their scope and
 * window, so `assertRoleAssigned($user, 'editor')` matches them too, and
 * `assertRoleAssigned($user, 'editor', scope: $team, until: $friday)` narrows to them.
 *
 * @phpstan-type Recorded array{kind: string, holder: Model|null, names: list<string>, scope: Model|null, from: CarbonImmutable|null, until: CarbonImmutable|null}
 */
final class PermissionsFake extends PermissionsManager
{
    /** @var list<Recorded> */
    private array $recorded = [];

    public function role(string|BackedEnum $name): Role
    {
        $role = parent::role($name);

        $this->record('roleRegistered', names: [$role->name]);

        return $role;
    }

    public function permission(string|BackedEnum $name): Permission
    {
        $permission = parent::permission($name);

        $this->record('permissionRegistered', names: [$permission->name]);

        return $permission;
    }

    public function syncFrom(string $enum): SyncResult
    {
        $result = parent::syncFrom($enum);

        $this->record('syncedFrom', names: [$enum]);

        return $result;
    }

    public function syncRolesFrom(string $enum): SyncResult
    {
        $result = parent::syncRolesFrom($enum);

        $this->record('syncedFrom', names: [$enum]);

        return $result;
    }

    public function pruneOrphans(): int
    {
        $pruned = parent::pruneOrphans();

        $this->record('pruned');

        return $pruned;
    }

    public function forgetScope(Model $scope): int
    {
        $deleted = parent::forgetScope($scope);

        $this->record('scopeForgotten', scope: $scope);

        return $deleted;
    }

    public function pruneExpired(?DateTimeInterface $endedBefore = null): int
    {
        $pruned = parent::pruneExpired($endedBefore);

        $this->record('expiredPruned');

        return $pruned;
    }

    /** @internal */
    public function ownedRole(Model $owner, string|BackedEnum $name): Role
    {
        $role = parent::ownedRole($owner, $name);

        $this->record('roleRegistered', names: [$role->name], scope: $owner);

        return $role;
    }

    /** @internal */
    public function syncOwnedRolesFrom(Model $owner, string $enum): SyncResult
    {
        $result = parent::syncOwnedRolesFrom($owner, $enum);

        $this->record('syncedFrom', names: [$enum], scope: $owner);

        return $result;
    }

    /** @internal */
    public function grantAssignedRoles(Model $holder, iterable $roles, GrantMode $mode, AssignmentContext $context): void
    {
        $roles = Grants::flatten($roles);

        parent::grantAssignedRoles($holder, $roles, $mode, $context);

        $this->record($mode->detaches() ? 'rolesSynced' : 'roleAssigned', $holder, $this->roleNames($roles), $context->scope, $context->validFrom, $context->validUntil);
    }

    /** @internal */
    public function removeAssignedRoles(Model $holder, iterable $roles, Model $scope): void
    {
        $roles = Grants::flatten($roles);

        parent::removeAssignedRoles($holder, $roles, $scope);

        $this->record('roleRemoved', $holder, $this->roleNames($roles), $scope);
    }

    /** @internal */
    public function grantAssignedPermissions(Model $holder, iterable $permissions, GrantMode $mode, AssignmentContext $context): void
    {
        $permissions = Grants::flatten($permissions);

        parent::grantAssignedPermissions($holder, $permissions, $mode, $context);

        $this->record(
            $mode->detaches() ? 'permissionsSynced' : 'permissionGranted',
            $holder,
            $this->permissionNames($permissions),
            $context->scope,
            $context->validFrom,
            $context->validUntil,
        );
    }

    /** @internal */
    public function revokeAssignedPermissions(Model $holder, iterable $permissions, Model $scope): void
    {
        $permissions = Grants::flatten($permissions);

        parent::revokeAssignedPermissions($holder, $permissions, $scope);

        $this->record('permissionRevoked', $holder, $this->permissionNames($permissions), $scope);
    }

    /** @internal */
    public function forgetAssignedAuthorization(Model $holder, Model $scope): void
    {
        parent::forgetAssignedAuthorization($holder, $scope);

        $this->record('authorizationForgotten', $holder, scope: $scope);
    }

    /** @internal */
    public function grantRoles(Model $holder, iterable $roles, GrantMode $mode): void
    {
        $roles = Grants::flatten($roles);

        parent::grantRoles($holder, $roles, $mode);

        $this->record($mode->detaches() ? 'rolesSynced' : 'roleAssigned', $holder, $this->roleNames($roles));
    }

    /** @internal */
    public function removeRoles(Model $holder, iterable $roles): void
    {
        $roles = Grants::flatten($roles);

        parent::removeRoles($holder, $roles);

        $this->record('roleRemoved', $holder, $this->roleNames($roles));
    }

    /** @internal */
    public function grantPermissions(Model $holder, iterable $permissions, GrantMode $mode): void
    {
        $permissions = Grants::flatten($permissions);

        parent::grantPermissions($holder, $permissions, $mode);

        $this->record(
            $mode->detaches() ? 'permissionsSynced' : 'permissionGranted',
            $holder,
            $this->permissionNames($permissions),
        );
    }

    /** @internal */
    public function revokePermissions(Model $holder, iterable $permissions): void
    {
        $permissions = Grants::flatten($permissions);

        parent::revokePermissions($holder, $permissions);

        $this->record('permissionRevoked', $holder, $this->permissionNames($permissions));
    }

    /** @internal */
    public function forgetAuthorization(Model $holder): void
    {
        parent::forgetAuthorization($holder);

        $this->record('authorizationForgotten', $holder);
    }

    /** @internal */
    public function forgetCache(): void
    {
        parent::forgetCache();

        $this->record('cacheForgotten');
    }

    /** @internal */
    public function flushMemo(): void
    {
        parent::flushMemo();

        $this->record('memoFlushed');
    }

    /**
     * A role of this name was registered through `role()` / `Role::findOrCreate()` — or, with
     * `owner:`, through `owner($owner)->role()`.
     */
    public function assertRoleRegistered(string|BackedEnum $name, ?Model $owner = null): void
    {
        Assert::assertTrue(
            $this->matching('roleRegistered', null, [PermissionRegistrar::nameOf($name)], $owner) !== [],
            'Expected role ['.PermissionRegistrar::nameOf($name).'] to be registered'.$this->describeScope($owner, null, null, 'for').'.',
        );
    }

    public function assertNoRoleRegistered(): void
    {
        $this->assertNone('roleRegistered', 'no role to be registered');
    }

    /** A permission of this name was registered through `permission()` / `Permission::findOrCreate()`. */
    public function assertPermissionRegistered(string|BackedEnum $name): void
    {
        Assert::assertTrue(
            $this->matching('permissionRegistered', null, [PermissionRegistrar::nameOf($name)]) !== [],
            'Expected permission ['.PermissionRegistrar::nameOf($name).'] to be registered.',
        );
    }

    public function assertNoPermissionRegistered(): void
    {
        $this->assertNone('permissionRegistered', 'no permission to be registered');
    }

    /** `syncFrom()` or `syncRolesFrom()` ran for this enum. */
    public function assertSyncedFrom(string $enum): void
    {
        Assert::assertTrue(
            $this->matching('syncedFrom', null, [$enum]) !== [],
            "Expected a catalog sync from [{$enum}].",
        );
    }

    public function assertNothingSyncedFrom(): void
    {
        $this->assertNone('syncedFrom', 'no catalog sync from an enum');
    }

    /**
     * The holder was assigned roles — this one, when given; in this scope and with this window
     * edge, when given.
     */
    public function assertRoleAssigned(
        Model $holder,
        string|BackedEnum|Role|null $role = null,
        ?Model $scope = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): void {
        $this->assertHolderRecorded('roleAssigned', $holder, $this->roleNames([$role]), 'assigned', 'role', $scope, $from, $until);
    }

    public function assertNoRoleAssigned(): void
    {
        $this->assertNone('roleAssigned', 'no role to be assigned');
    }

    /** The holder had roles removed — this one, when given; in this scope, when given. */
    public function assertRoleRemoved(Model $holder, string|BackedEnum|Role|null $role = null, ?Model $scope = null): void
    {
        $this->assertHolderRecorded('roleRemoved', $holder, $this->roleNames([$role]), 'removed', 'role', $scope);
    }

    public function assertNoRoleRemoved(): void
    {
        $this->assertNone('roleRemoved', 'no role to be removed');
    }

    /**
     * The holder's roles were synced — to exactly this set, when given; in this scope, when given.
     *
     * @param  iterable<mixed>|null  $roles
     */
    public function assertRolesSynced(Model $holder, ?iterable $roles = null, ?Model $scope = null): void
    {
        $this->assertSyncedExactly('rolesSynced', $holder, $roles === null ? null : $this->roleNames($roles), 'roles', $scope);
    }

    public function assertNoRolesSynced(): void
    {
        $this->assertNone('rolesSynced', 'no roles to be synced');
    }

    /**
     * The holder was given direct permissions — this one, when given; in this scope and with
     * this window edge, when given.
     */
    public function assertPermissionGranted(
        Model $holder,
        string|BackedEnum|Permission|null $permission = null,
        ?Model $scope = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): void {
        $this->assertHolderRecorded('permissionGranted', $holder, $this->permissionNames([$permission]), 'granted', 'permission', $scope, $from, $until);
    }

    public function assertNoPermissionGranted(): void
    {
        $this->assertNone('permissionGranted', 'no permission to be granted');
    }

    /** The holder had direct permissions revoked — this one, when given; in this scope, when given. */
    public function assertPermissionRevoked(Model $holder, string|BackedEnum|Permission|null $permission = null, ?Model $scope = null): void
    {
        $this->assertHolderRecorded('permissionRevoked', $holder, $this->permissionNames([$permission]), 'revoked', 'permission', $scope);
    }

    public function assertNoPermissionRevoked(): void
    {
        $this->assertNone('permissionRevoked', 'no permission to be revoked');
    }

    /**
     * The holder's direct permissions were synced — to exactly this set, when given; in this
     * scope, when given.
     *
     * @param  iterable<mixed>|null  $permissions
     */
    public function assertPermissionsSynced(Model $holder, ?iterable $permissions = null, ?Model $scope = null): void
    {
        $this->assertSyncedExactly(
            'permissionsSynced',
            $holder,
            $permissions === null ? null : $this->permissionNames($permissions),
            'permissions',
            $scope,
        );
    }

    public function assertNoPermissionsSynced(): void
    {
        $this->assertNone('permissionsSynced', 'no permissions to be synced');
    }

    /** `forgetAllAuthorization()` ran for the holder — inside this scope, when given. */
    public function assertAuthorizationForgotten(Model $holder, ?Model $scope = null): void
    {
        Assert::assertTrue(
            $this->matching('authorizationForgotten', $holder, [], $scope) !== [],
            'Expected the authorization of ['.$holder::class.'#'.$this->keyOf($holder).'] to be forgotten'.$this->describeScope($scope, null, null).'.',
        );
    }

    public function assertNoAuthorizationForgotten(): void
    {
        $this->assertNone('authorizationForgotten', 'no authorization to be forgotten');
    }

    public function assertOrphansPruned(): void
    {
        Assert::assertTrue($this->matching('pruned', null, []) !== [], 'Expected orphaned grants to be pruned.');
    }

    public function assertNoOrphansPruned(): void
    {
        $this->assertNone('pruned', 'no orphan pruning');
    }

    /** `forgetScope()` ran — for this scope, when given. */
    public function assertScopeForgotten(?Model $scope = null): void
    {
        Assert::assertTrue(
            $this->matching('scopeForgotten', null, [], $scope) !== [],
            'Expected a scope to be forgotten'.$this->describeScope($scope, null, null).'.',
        );
    }

    public function assertNoScopeForgotten(): void
    {
        $this->assertNone('scopeForgotten', 'no scope to be forgotten');
    }

    /** `pruneExpired()` (or `permissions:prune-expired`) ran. */
    public function assertExpiredPruned(): void
    {
        Assert::assertTrue($this->matching('expiredPruned', null, []) !== [], 'Expected expired assignments to be pruned.');
    }

    public function assertNoExpiredPruned(): void
    {
        $this->assertNone('expiredPruned', 'no expired-assignment pruning');
    }

    /** `Permissions::cache()->forget()` (or `permissions:cache-reset`) ran. */
    public function assertCacheForgotten(): void
    {
        Assert::assertTrue($this->matching('cacheForgotten', null, []) !== [], 'Expected the permission cache to be forgotten.');
    }

    public function assertCacheNotForgotten(): void
    {
        $this->assertNone('cacheForgotten', 'the permission cache not to be forgotten');
    }

    /** `Permissions::cache()->flushMemo()` ran. */
    public function assertMemoFlushed(): void
    {
        Assert::assertTrue($this->matching('memoFlushed', null, []) !== [], 'Expected the permission memo to be flushed.');
    }

    public function assertMemoNotFlushed(): void
    {
        $this->assertNone('memoFlushed', 'the permission memo not to be flushed');
    }

    /** @param  list<string>  $names */
    private function record(
        string $kind,
        ?Model $holder = null,
        array $names = [],
        ?Model $scope = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
    ): void {
        $this->recorded[] = ['kind' => $kind, 'holder' => $holder, 'names' => $names, 'scope' => $scope, 'from' => $from, 'until' => $until];
    }

    /**
     * Recorded calls of this kind, for this holder (when given), naming every one of `$names`.
     *
     * @param  list<string>  $names
     * @return list<Recorded>
     */
    private function matching(
        string $kind,
        ?Model $holder,
        array $names,
        ?Model $scope = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): array {
        $from = $from === null ? null : Scopes::utc($from);
        $until = $until === null ? null : Scopes::utc($until);

        return array_values(array_filter(
            $this->recorded,
            static fn (array $call): bool => $call['kind'] === $kind
                && ($holder === null || ($call['holder'] instanceof Model && $call['holder']->is($holder)))
                && array_diff($names, $call['names']) === []
                && ($scope === null || ($call['scope'] instanceof Model && $call['scope']->is($scope)))
                && ($from === null || ($call['from'] !== null && $call['from']->equalTo($from)))
                && ($until === null || ($call['until'] !== null && $call['until']->equalTo($until))),
        ));
    }

    private function describeScope(?Model $scope, ?DateTimeInterface $from, ?DateTimeInterface $until, string $preposition = 'in'): string
    {
        $parts = [];

        if ($scope !== null) {
            $parts[] = "{$preposition} [".$scope::class.'#'.$this->keyOf($scope).']';
        }

        if ($from !== null) {
            $parts[] = 'from '.Scopes::utc($from)->toIso8601String();
        }

        if ($until !== null) {
            $parts[] = 'until '.Scopes::utc($until)->toIso8601String();
        }

        return $parts === [] ? '' : ' '.implode(' ', $parts);
    }

    /** @param  list<string>  $names */
    private function assertHolderRecorded(
        string $kind,
        Model $holder,
        array $names,
        string $verb,
        string $noun,
        ?Model $scope = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): void {
        $what = $names === [] ? "a {$noun}" : "{$noun} [".implode(', ', $names).']';

        Assert::assertTrue(
            $this->matching($kind, $holder, $names, $scope, $from, $until) !== [],
            "Expected {$what} to be {$verb} for [".$holder::class.'#'.$this->keyOf($holder).']'.$this->describeScope($scope, $from, $until).'.',
        );
    }

    /** @param  list<string>|null  $names */
    private function assertSyncedExactly(string $kind, Model $holder, ?array $names, string $noun, ?Model $scope = null): void
    {
        $calls = $this->matching($kind, $holder, [], $scope);

        if ($names !== null) {
            sort($names);
            $calls = array_filter($calls, static function (array $call) use ($names): bool {
                $recorded = $call['names'];
                sort($recorded);

                return $recorded === $names;
            });
        }

        $target = $names === null ? '' : ' to ['.implode(', ', $names).']';

        Assert::assertNotEmpty(
            $calls,
            "Expected the {$noun} of [".$holder::class.'#'.$this->keyOf($holder)."] to be synced{$target}".$this->describeScope($scope, null, null).'.',
        );
    }

    private function assertNone(string $kind, string $expectation): void
    {
        $count = count($this->matching($kind, null, []));

        Assert::assertSame(0, $count, "Expected {$expectation}, but it happened {$count} time(s).");
    }

    /**
     * @param  iterable<mixed>  $roles
     * @return list<string>
     */
    private function roleNames(iterable $roles): array
    {
        return Grants::names(array_filter(Grants::flatten($roles), static fn (mixed $role): bool => $role !== null), Role::class, 'Role');
    }

    /**
     * @param  iterable<mixed>  $permissions
     * @return list<string>
     */
    private function permissionNames(iterable $permissions): array
    {
        return Grants::names(array_filter(Grants::flatten($permissions), static fn (mixed $permission): bool => $permission !== null), Permission::class, 'Permission');
    }

    private function keyOf(Model $holder): string
    {
        $key = $holder->getKey();

        return is_scalar($key) ? (string) $key : '?';
    }
}
