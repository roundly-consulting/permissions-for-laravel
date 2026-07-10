<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use BackedEnum;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Permissions\Enums\ModelKeyType;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * The single public service: model resolution from config, the permission
 * catalog cache, and name normalization (string|BackedEnum -> string).
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

        $model = self::permissionModel();

        $loader = static fn (): Collection => $model::query()->get(['id', 'name']);

        /** @var Collection<int, Permission> $permissions */
        $permissions = $this->cacheStore()->remember(self::cacheKey(), self::cacheTtl(), $loader);

        return $this->permissions = $permissions;
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
        /** @var class-string<Role> $model */
        $model = config('permissions.models.role', Role::class);

        return $model;
    }

    /** @return class-string<Permission> */
    public static function permissionModel(): string
    {
        /** @var class-string<Permission> $model */
        $model = config('permissions.models.permission', Permission::class);

        return $model;
    }

    public static function tableName(string $key, string $default): string
    {
        $name = config("permissions.table_names.{$key}", $default);

        return is_string($name) ? $name : $default;
    }

    /**
     * The key type of the models that hold roles/permissions (drives `model_id`).
     */
    public static function modelKeyType(): ModelKeyType
    {
        return ModelKeyType::fromConfig(config('permissions.model_key_type', 'bigint'));
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
