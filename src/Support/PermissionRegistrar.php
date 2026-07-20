<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * The single public service: model resolution from config, the permission
 * catalog cache, and name normalization (string|BackedEnum -> string).
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

    public function __construct(private readonly CacheFactory $cache) {}

    /**
     * The permission catalog (id + name), cached; relations resolve live.
     *
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
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

    public function permissionExists(string|BackedEnum $name): bool
    {
        $name = self::nameOf($name);

        return $this->getPermissions()->contains(
            static fn (Permission $permission): bool => $permission->name === $name,
        );
    }

    public function forgetCachedPermissions(): void
    {
        $this->permissions = null;
        $this->cacheStore()->forget(self::cacheKey());
    }

    /**
     * Drop only the in-process memo, leaving the shared cache store intact.
     *
     * Called at request/job boundaries (Octane, queue workers) so a long-lived
     * worker never serves a memo that outlived its authority — the next lookup
     * re-reads the shared store, which invalidation propagates through.
     */
    public function flushMemo(): void
    {
        $this->permissions = null;
    }

    public static function nameOf(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    /** @return class-string<Role> */
    public static function roleModel(): string
    {
        return RoleModel::class();
    }

    /** @return class-string<Permission> */
    public static function permissionModel(): string
    {
        return PermissionModel::class();
    }

    public static function rolesTable(): string
    {
        return self::tableName('permissions.table_names.roles', 'roles');
    }

    public static function permissionsTable(): string
    {
        return self::tableName('permissions.table_names.permissions', 'permissions');
    }

    public static function permissionRoleTable(): string
    {
        return self::tableName('permissions.table_names.permission_role', 'permission_role');
    }

    public static function modelRolesTable(): string
    {
        return self::tableName('permissions.table_names.model_roles', 'model_roles');
    }

    public static function modelPermissionsTable(): string
    {
        return self::tableName('permissions.table_names.model_permissions', 'model_permissions');
    }

    /**
     * The key type of the models that hold roles/permissions (drives `model_id`).
     *
     * Misconfiguration never throws — an unrecognized value silently falls back to
     * the safe `bigint` default, which is what the schema has always emitted.
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
