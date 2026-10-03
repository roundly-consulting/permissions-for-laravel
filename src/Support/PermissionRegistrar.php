<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionsManager;

/**
 * The permission catalog cache and the package's config resolvers.
 *
 * - **Instance side** — the cached catalog (id + name), its in-process memo, and
 *   invalidation. A singleton owned by {@see PermissionsManager}; host code uses
 *   `Permissions::permissions()`, `Permissions::exists()` and `Permissions::cache()`,
 *   so every instance method here is `@internal`.
 *
 *   Invalidation follows the transaction, not the write: the shared store is shared with
 *   processes that cannot see an uncommitted row, so it is dropped when the outermost
 *   transaction commits. Until then this process reads the catalog live and caches nothing
 *   (its own write is visible to it; a rollback leaves nothing behind).
 * - **Static side** — public config resolvers: the configured model classes, the five
 *   table names and the holder key type. The published migrations call them, and host
 *   code may too (a custom migration or a raw query that must follow `table_names`).
 *
 * Every config read here is a **literal** key, so the config-contract test can
 * scrape them out of the source and pin them against the shipped config file.
 */
final class PermissionRegistrar
{
    /**
     * In-request memo of the cached permission catalog.
     *
     * @var Collection<int, Permission>|null
     */
    private ?Collection $permissions = null;

    /**
     * Connections with a catalog write that has not committed yet, by name.
     *
     * @var array<string, Connection>
     */
    private array $uncommitted = [];

    public function __construct(private readonly CacheFactory $cache) {}

    /**
     * The permission catalog (id + name), cached; relations resolve live.
     *
     * @internal Use `Permissions::permissions()`.
     *
     * @return Collection<int, Permission>
     */
    public function permissions(): Collection
    {
        $this->settleEndedTransactions();

        if ($this->uncommitted !== []) {
            // This process wrote inside a transaction that has not committed: only its own
            // connection sees the write, and it may still roll back. Read live, cache nothing.
            return $this->hydrate(self::rowLoader()());
        }

        if ($this->permissions instanceof Collection) {
            return $this->permissions;
        }

        return $this->permissions = $this->hydrate($this->cachedRows());
    }

    /**
     * The cached catalog as plain rows.
     *
     * Only scalars are ever written to the cache — never Eloquent models. A host
     * may configure the cache to unserialize no classes at all
     * (`cache.serializable_classes`, which Laravel ships as `false`), and cached
     * objects then come back as `__PHP_Incomplete_Class`, breaking every read.
     * Scalars are immune to that, smaller on the wire, and all this catalog
     * needs — relations still resolve live.
     *
     * @return list<array{id: int|string, name: string}>
     */
    private function cachedRows(): array
    {
        $store = $this->cacheStore();
        $loader = self::rowLoader();

        // Deliberately `mixed`: whatever a previous release (or another app
        // sharing this store) wrote under the key comes back here, so the shape
        // is only known after the check below.
        /** @var mixed $rows */
        $rows = $store->remember(self::cacheKey(), self::cacheTtl(), $loader);

        if (self::isRowList($rows)) {
            return $rows;
        }

        // A payload in a shape this version does not understand (written by an
        // older release, or unreadable under the host's unserialize policy).
        // Rebuild it rather than failing the request.
        $rows = $loader();
        $store->put(self::cacheKey(), $rows, self::cacheTtl());

        return $rows;
    }

    /**
     * @return Closure(): list<array{id: int|string, name: string}>
     */
    private static function rowLoader(): Closure
    {
        $model = self::permissionModel();

        return static function () use ($model): array {
            $rows = [];

            foreach ($model::query()->get(['id', 'name']) as $permission) {
                $key = $permission->getKey();

                $rows[] = [
                    'id' => is_int($key) ? $key : (string) $key,
                    'name' => (string) $permission->name,
                ];
            }

            return $rows;
        };
    }

    /**
     * Rebuilds catalog models from cached scalars, marked as existing records so
     * they behave like the query-loaded models callers used to receive.
     *
     * @param  list<array{id: int|string, name: string}>  $rows
     * @return Collection<int, Permission>
     */
    private function hydrate(array $rows): Collection
    {
        $model = self::permissionModel();
        $instance = new $model;

        /** @var Collection<int, Permission> $collection */
        $collection = $instance->newCollection(array_map(
            static fn (array $row): Permission => $instance->newFromBuilder([
                $instance->getKeyName() => $row['id'],
                'name' => $row['name'],
            ]),
            $rows,
        ));

        return $collection;
    }

    /**
     * @phpstan-assert-if-true list<array{id: int|string, name: string}> $rows
     */
    private static function isRowList(mixed $rows): bool
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null)) {
                return false;
            }

            if (! is_int($row['id'] ?? null) && ! is_string($row['id'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal Use `Permissions::exists()`.
     */
    public function exists(string|BackedEnum $name): bool
    {
        $name = self::nameOf($name);

        return $this->permissions()->contains(
            static fn (Permission $permission): bool => $permission->name === $name,
        );
    }

    /**
     * Drop the catalog from the shared store and the in-process memo — now, and again when
     * the catalog connection's open transaction commits (a bulk write inside it is invisible
     * to everyone else until then, and they may re-cache the old catalog meanwhile).
     *
     * @internal Use `Permissions::cache()->forget()`.
     */
    public function forget(): void
    {
        $this->drop();

        $connection = PermissionModel::new()->getConnection();

        if ($connection->transactionLevel() > 0) {
            $this->defer($connection);
        }
    }

    /**
     * Invalidate after a catalog write on this connection: straight away outside a
     * transaction, otherwise when its outermost transaction commits.
     *
     * Forgetting inside the transaction would be too early — a concurrent request (another
     * connection) would re-cache the pre-commit catalog for the whole TTL.
     *
     * @internal The package's model events and grant actions call this.
     */
    public function forgetAfterCommit(Connection $connection): void
    {
        if ($connection->transactionLevel() === 0) {
            $this->drop();

            return;
        }

        $this->defer($connection);
    }

    /**
     * A transaction on this connection committed or rolled back. Once the outermost one
     * ends: a commit drops the shared store (the write is now visible to everyone); a
     * rollback has nothing to undo, because nothing uncommitted was ever cached.
     *
     * @internal The provider calls this from the `TransactionCommitted` /
     * `TransactionRolledBack` connection events.
     */
    public function settle(Connection $connection, bool $committed): void
    {
        $name = (string) $connection->getName();

        if (! isset($this->uncommitted[$name]) || $connection->transactionLevel() > 0) {
            return;
        }

        unset($this->uncommitted[$name]);

        $committed ? $this->drop() : $this->flushMemo();
    }

    /**
     * Drop only the in-process memo, leaving the shared cache store intact.
     *
     * Called at request/job boundaries (Octane, queue workers) so a long-lived
     * worker never serves a memo that outlived its authority — the next lookup
     * re-reads the shared store, which invalidation propagates through.
     *
     * @internal Use `Permissions::cache()->flushMemo()`.
     */
    public function flushMemo(): void
    {
        $this->permissions = null;
    }

    private function drop(): void
    {
        $this->permissions = null;
        $this->cacheStore()->forget(self::cacheKey());
    }

    private function defer(Connection $connection): void
    {
        $this->permissions = null;
        $this->uncommitted[(string) $connection->getName()] = $connection;
    }

    /**
     * A transaction that ended without its connection event reaching {@see settle()} (no
     * dispatcher, a faked one, a lost connection) is settled on the next read. Whether it
     * committed is unknown, and dropping the store is right either way.
     */
    private function settleEndedTransactions(): void
    {
        foreach ($this->uncommitted as $name => $connection) {
            if ($connection->transactionLevel() === 0) {
                unset($this->uncommitted[$name]);
                $this->drop();
            }
        }
    }

    /**
     * Normalize a name: a backed enum becomes its `value`.
     *
     * @internal Every name-taking method already accepts either form.
     */
    public static function nameOf(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    /**
     * The configured role class (`permissions.models.role`); `Permissions::roleModel()` is the
     * facade form.
     *
     * @return class-string<Role>
     */
    public static function roleModel(): string
    {
        return RoleModel::class();
    }

    /**
     * The configured permission class (`permissions.models.permission`);
     * `Permissions::permissionModel()` is the facade form.
     *
     * @return class-string<Permission>
     */
    public static function permissionModel(): string
    {
        return PermissionModel::class();
    }

    /** The `table_names.roles` table. */
    public static function rolesTable(): string
    {
        return self::tableName('permissions.table_names.roles', 'roles');
    }

    /** The `table_names.permissions` table. */
    public static function permissionsTable(): string
    {
        return self::tableName('permissions.table_names.permissions', 'permissions');
    }

    /** The `table_names.permission_role` role↔permission pivot. */
    public static function permissionRoleTable(): string
    {
        return self::tableName('permissions.table_names.permission_role', 'permission_role');
    }

    /** The `table_names.model_roles` holder↔role morph pivot. */
    public static function modelRolesTable(): string
    {
        return self::tableName('permissions.table_names.model_roles', 'model_roles');
    }

    /** The `table_names.model_permissions` holder↔permission morph pivot. */
    public static function modelPermissionsTable(): string
    {
        return self::tableName('permissions.table_names.model_permissions', 'model_permissions');
    }

    /**
     * The key type of the models that hold roles/permissions (drives `model_id`).
     *
     * Absent or null reads as `bigint`; an unrecognized value throws the toolkit's
     * `InvalidConfigurationException` rather than silently building bigint keys.
     */
    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('permissions.key_type');
    }

    private static function tableName(string $key, string $default): string
    {
        $name = config($key, $default);

        return is_string($name) ? $name : $default;
    }

    private function cacheStore(): CacheRepository
    {
        $store = config('permissions.cache.store');
        $store = ($store === 'default' || ! is_string($store)) ? null : $store;

        return $this->cache->store($store);
    }

    private static function cacheKey(): string
    {
        $key = config('permissions.cache.key', 'permissions.cache');

        return is_string($key) ? $key : 'permissions.cache';
    }

    private static function cacheTtl(): int
    {
        $ttl = config('permissions.cache.ttl', 300);

        return is_int($ttl) ? $ttl : 300;
    }
}
