<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Testing;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\Grants;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

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
 * @phpstan-type Recorded array{kind: string, holder: Model|null, names: list<string>}
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

    /** A role of this name was registered through `role()` / `Role::findOrCreate()`. */
    public function assertRoleRegistered(string|BackedEnum $name): void
    {
        Assert::assertTrue(
            $this->matching('roleRegistered', null, [PermissionRegistrar::nameOf($name)]) !== [],
            'Expected role ['.PermissionRegistrar::nameOf($name).'] to be registered.',
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

    /** The holder was assigned roles — this one, when given. */
    public function assertRoleAssigned(Model $holder, string|BackedEnum|Role|null $role = null): void
    {
        $this->assertHolderRecorded('roleAssigned', $holder, $this->roleNames([$role]), 'assigned', 'role');
    }

    public function assertNoRoleAssigned(): void
    {
        $this->assertNone('roleAssigned', 'no role to be assigned');
    }

    /** The holder had roles removed — this one, when given. */
    public function assertRoleRemoved(Model $holder, string|BackedEnum|Role|null $role = null): void
    {
        $this->assertHolderRecorded('roleRemoved', $holder, $this->roleNames([$role]), 'removed', 'role');
    }

    public function assertNoRoleRemoved(): void
    {
        $this->assertNone('roleRemoved', 'no role to be removed');
    }

    /**
     * The holder's roles were synced — to exactly this set, when given.
     *
     * @param  iterable<mixed>|null  $roles
     */
    public function assertRolesSynced(Model $holder, ?iterable $roles = null): void
    {
        $this->assertSyncedExactly('rolesSynced', $holder, $roles === null ? null : $this->roleNames($roles), 'roles');
    }

    public function assertNoRolesSynced(): void
    {
        $this->assertNone('rolesSynced', 'no roles to be synced');
    }

    /** The holder was given direct permissions — this one, when given. */
    public function assertPermissionGranted(Model $holder, string|BackedEnum|Permission|null $permission = null): void
    {
        $this->assertHolderRecorded('permissionGranted', $holder, $this->permissionNames([$permission]), 'granted', 'permission');
    }

    public function assertNoPermissionGranted(): void
    {
        $this->assertNone('permissionGranted', 'no permission to be granted');
    }

    /** The holder had direct permissions revoked — this one, when given. */
    public function assertPermissionRevoked(Model $holder, string|BackedEnum|Permission|null $permission = null): void
    {
        $this->assertHolderRecorded('permissionRevoked', $holder, $this->permissionNames([$permission]), 'revoked', 'permission');
    }

    public function assertNoPermissionRevoked(): void
    {
        $this->assertNone('permissionRevoked', 'no permission to be revoked');
    }

    /**
     * The holder's direct permissions were synced — to exactly this set, when given.
     *
     * @param  iterable<mixed>|null  $permissions
     */
    public function assertPermissionsSynced(Model $holder, ?iterable $permissions = null): void
    {
        $this->assertSyncedExactly(
            'permissionsSynced',
            $holder,
            $permissions === null ? null : $this->permissionNames($permissions),
            'permissions',
        );
    }

    public function assertNoPermissionsSynced(): void
    {
        $this->assertNone('permissionsSynced', 'no permissions to be synced');
    }

    /** `forgetAllAuthorization()` ran for the holder. */
    public function assertAuthorizationForgotten(Model $holder): void
    {
        Assert::assertTrue(
            $this->matching('authorizationForgotten', $holder, []) !== [],
            'Expected the authorization of ['.$holder::class.'#'.$this->keyOf($holder).'] to be forgotten.',
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
    private function record(string $kind, ?Model $holder = null, array $names = []): void
    {
        $this->recorded[] = ['kind' => $kind, 'holder' => $holder, 'names' => $names];
    }

    /**
     * Recorded calls of this kind, for this holder (when given), naming every one of `$names`.
     *
     * @param  list<string>  $names
     * @return list<Recorded>
     */
    private function matching(string $kind, ?Model $holder, array $names): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (array $call): bool => $call['kind'] === $kind
                && ($holder === null || ($call['holder'] instanceof Model && $call['holder']->is($holder)))
                && array_diff($names, $call['names']) === [],
        ));
    }

    /** @param  list<string>  $names */
    private function assertHolderRecorded(string $kind, Model $holder, array $names, string $verb, string $noun): void
    {
        $what = $names === [] ? "a {$noun}" : "{$noun} [".implode(', ', $names).']';

        Assert::assertTrue(
            $this->matching($kind, $holder, $names) !== [],
            "Expected {$what} to be {$verb} for [".$holder::class.'#'.$this->keyOf($holder).'].',
        );
    }

    /** @param  list<string>|null  $names */
    private function assertSyncedExactly(string $kind, Model $holder, ?array $names, string $noun): void
    {
        $calls = $this->matching($kind, $holder, []);

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
            "Expected the {$noun} of [".$holder::class.'#'.$this->keyOf($holder)."] to be synced{$target}.",
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
